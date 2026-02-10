#!/bin/bash

# ============================================================================
# Azure Alt-Text Pipeline - Test End-to-End
# ============================================================================
# Uploads ALL images from ./assets/ and monitors the pipeline
# Usage: ./scripts/04-test-end-to-end.sh
# ============================================================================

set -e

# Resolve script directory for reliable path references
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# Load deployment output variables
if [ ! -f "$PROJECT_DIR/.deployment-output" ]; then
    echo "❌ Error: .deployment-output not found"
    echo "   Run 01-deploy-infrastructure.sh first"
    exit 1
fi

source "$PROJECT_DIR/.deployment-output"

ASSETS_DIR="$PROJECT_DIR/assets"

# ============================================================================
# 1. Discover Images in Assets Folder
# ============================================================================

IMAGES=()
for img in "$ASSETS_DIR"/*.png "$ASSETS_DIR"/*.jpg "$ASSETS_DIR"/*.jpeg "$ASSETS_DIR"/*.gif "$ASSETS_DIR"/*.webp; do
  [[ -f "$img" ]] && IMAGES+=("$img")
done

if [ ${#IMAGES[@]} -eq 0 ]; then
    echo "❌ Error: No images found in $ASSETS_DIR"
    exit 1
fi

echo "📋 Found ${#IMAGES[@]} image(s) in assets folder"

# ============================================================================
# 2. Upload All Assets to Ingest Container
# ============================================================================

echo ""
echo "📤 Uploading assets to ingest container..."

# First upload YAML sidecars (so they are available when images trigger the pipeline)
for img in "${IMAGES[@]}"; do
  BASENAME=$(basename "$img")
  STEM="${BASENAME%.*}"
  YML="$ASSETS_DIR/${STEM}.yml"
  YAML="$ASSETS_DIR/${STEM}.yaml"

  if [[ -f "$YML" ]]; then
    echo "   📄 Uploading sidecar: ${STEM}.yml"
    az storage blob upload \
      --account-name "$STORAGE_ACCOUNT" \
      --auth-mode login \
      --container-name "ingest" \
      --name "${STEM}.yml" \
      --file "$YML" \
      --content-type "application/yaml" \
      --overwrite \
      --only-show-errors
  elif [[ -f "$YAML" ]]; then
    echo "   📄 Uploading sidecar: ${STEM}.yaml"
    az storage blob upload \
      --account-name "$STORAGE_ACCOUNT" \
      --auth-mode login \
      --container-name "ingest" \
      --name "${STEM}.yaml" \
      --file "$YAML" \
      --content-type "application/yaml" \
      --overwrite \
      --only-show-errors
  fi
done

# Then upload images (triggers Event Grid)
IMAGE_NAMES=()
for img in "${IMAGES[@]}"; do
  BASENAME=$(basename "$img")
  EXT="${BASENAME##*.}"

  # Determine content type
  case "$EXT" in
    png)  CT="image/png" ;;
    jpg|jpeg) CT="image/jpeg" ;;
    gif)  CT="image/gif" ;;
    webp) CT="image/webp" ;;
    *)    CT="application/octet-stream" ;;
  esac

  echo "   🖼️  Uploading image: $BASENAME"
  az storage blob upload \
    --account-name "$STORAGE_ACCOUNT" \
    --auth-mode login \
    --container-name "ingest" \
    --name "$BASENAME" \
    --file "$img" \
    --content-type "$CT" \
    --overwrite \
    --only-show-errors

  IMAGE_NAMES+=("$BASENAME")
done

echo ""
echo "✅ ${#IMAGE_NAMES[@]} image(s) uploaded to: https://$STORAGE_ACCOUNT.blob.core.windows.net/ingest/"

# ============================================================================
# 3. Wait for Event Grid Processing
# ============================================================================

echo ""
echo "⏳ Waiting for Event Grid to trigger pipeline..."
echo "   (Processing ${#IMAGE_NAMES[@]} images — usually 10-30s each)"

# Scale wait time with image count: 30s base + 10s per extra image
INITIAL_WAIT=$((30 + (${#IMAGE_NAMES[@]} - 1) * 10))
[[ $INITIAL_WAIT -gt 180 ]] && INITIAL_WAIT=180
sleep $INITIAL_WAIT

# ============================================================================
# 4. Poll for All Alt-Text JSON Results
# ============================================================================

echo ""
echo "📄 Polling for alt-text JSON results..."

MAX_WAIT=120
ELAPSED=0
POLL_INTERVAL=5
FOUND_COUNT=0
FAILED_IMAGES=()

# Build list of expected alt.json names
declare -A FOUND_MAP
for name in "${IMAGE_NAMES[@]}"; do
  FOUND_MAP["$name"]="pending"
done

while [ $ELAPSED -lt $MAX_WAIT ] && [ $FOUND_COUNT -lt ${#IMAGE_NAMES[@]} ]; do
  for name in "${IMAGE_NAMES[@]}"; do
    [[ "${FOUND_MAP[$name]}" == "found" ]] && continue

    STEM="${name%.*}"
    ALT_JSON="${STEM}.alt.json"

    if az storage blob exists \
      --account-name "$STORAGE_ACCOUNT" \
      --auth-mode login \
      --container-name "ingest" \
      --name "$ALT_JSON" \
      --query exists -o tsv 2>/dev/null | grep -q true; then
      FOUND_MAP["$name"]="found"
      FOUND_COUNT=$((FOUND_COUNT + 1))
      echo "   ✅ $ALT_JSON found ($FOUND_COUNT/${#IMAGE_NAMES[@]})"
    fi
  done

  if [ $FOUND_COUNT -lt ${#IMAGE_NAMES[@]} ]; then
    ELAPSED=$((ELAPSED + POLL_INTERVAL))
    REMAINING=$((MAX_WAIT - ELAPSED))
    PENDING=$((${#IMAGE_NAMES[@]} - FOUND_COUNT))
    echo "   ⏳ Waiting for $PENDING more result(s)... (${REMAINING}s remaining)"
    sleep $POLL_INTERVAL
  fi
done

# ============================================================================
# 5. Download and Display Results
# ============================================================================

RESULTS_DIR="$PROJECT_DIR/results"
mkdir -p "$RESULTS_DIR"

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📊 Results: $FOUND_COUNT/${#IMAGE_NAMES[@]} alt-text files generated"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

for name in "${IMAGE_NAMES[@]}"; do
  STEM="${name%.*}"
  ALT_JSON="${STEM}.alt.json"

  if [[ "${FOUND_MAP[$name]}" == "found" ]]; then
    az storage blob download \
      --account-name "$STORAGE_ACCOUNT" \
      --auth-mode login \
      --container-name "ingest" \
      --name "$ALT_JSON" \
      --file "$RESULTS_DIR/$ALT_JSON" \
      --output none \
      --overwrite \
      --only-show-errors 2>/dev/null

    ALT_EN=$(jq -r '.altText.en // "N/A"' "$RESULTS_DIR/$ALT_JSON" 2>/dev/null)
    CONFIDENCE=$(jq -r '.confidence // "N/A"' "$RESULTS_DIR/$ALT_JSON" 2>/dev/null)
    LANGS=$(jq -r '.altText | keys | join(", ")' "$RESULTS_DIR/$ALT_JSON" 2>/dev/null)
    COMPLIANT=$(jq -r '.policyCompliant' "$RESULTS_DIR/$ALT_JSON" 2>/dev/null)

    echo "✅ $name"
    echo "   Alt:        $ALT_EN"
    echo "   Languages:  $LANGS"
    echo "   Confidence: $CONFIDENCE | Compliant: $COMPLIANT"
    echo ""
  else
    FAILED_IMAGES+=("$name")
    echo "❌ $name — alt.json NOT generated"
    echo ""
  fi
done

# ============================================================================
# 6. Summary
# ============================================================================

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

if [ ${#FAILED_IMAGES[@]} -eq 0 ]; then
  echo "🎉 End-to-end test SUCCESS! All ${#IMAGE_NAMES[@]} images processed."
else
  echo "⚠️  ${FOUND_COUNT}/${#IMAGE_NAMES[@]} images processed. ${#FAILED_IMAGES[@]} failed:"
  for f in "${FAILED_IMAGES[@]}"; do
    echo "   - $f"
  done
fi

echo ""
echo "💡 Next steps:"
echo "   - Review alt-text quality in $RESULTS_DIR/"
echo "   - Monitor ACA logs:"
echo "     az containerapp logs show -n $CONTAINER_APP_NAME -g $RESOURCE_GROUP --tail 200"
echo ""

# List blobs in ingest container
echo "📦 Blobs in ingest container:"
az storage blob list \
  --account-name "$STORAGE_ACCOUNT" \
  --auth-mode login \
  --container-name "ingest" \
  --output table

if [ ${#FAILED_IMAGES[@]} -gt 0 ]; then
  exit 1
fi
