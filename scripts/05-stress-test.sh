#!/bin/bash

# ============================================================================
# Azure Alt-Text Pipeline - Stress Test (Repeated End-to-End Tests)
# ============================================================================
# Runs the end-to-end test multiple times to stress test the pipeline
# Usage: ./scripts/05-stress-test.sh [iterations]
#        Default iterations: 1000
#        Example: ./scripts/05-stress-test.sh 100
# ============================================================================

set -e

# Resolve script directory for reliable path references
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# Configuration
ITERATIONS=${1:-500}
TEST_SCRIPT="$SCRIPT_DIR/04-test-end-to-end.sh"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
LOG_FILE="$PROJECT_DIR/stress-test-results_${TIMESTAMP}.log"

# Validation
if [ ! -f "$TEST_SCRIPT" ]; then
    echo "❌ Error: $TEST_SCRIPT not found"
    exit 1
fi

if ! [[ "$ITERATIONS" =~ ^[0-9]+$ ]] || [ "$ITERATIONS" -lt 1 ]; then
    echo "❌ Error: iterations must be a positive number"
    exit 1
fi

# Verify deployment output exists
if [ ! -f "$PROJECT_DIR/.deployment-output" ]; then
    echo "❌ Error: $PROJECT_DIR/.deployment-output not found"
    echo "   Run 01-deploy-infrastructure.sh first"
    exit 1
fi

# ============================================================================
# Initialize Test Run
# ============================================================================

echo "🚀 Starting stress test..."
echo "   Iterations: $ITERATIONS"
echo "   Test script: $TEST_SCRIPT"
echo "   Log file: $LOG_FILE"
echo ""

TEST_START_TIME=$(date +%s)
PASSED=0
FAILED=0
SKIPPED=0

# ============================================================================
# Main Test Loop
# ============================================================================

for ((i=1; i<=ITERATIONS; i++)); do
    ITERATION_START=$(date +%s)
    ITERATION_TIME=$(date "+%Y-%m-%d %H:%M:%S")
    
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "📊 Iteration $i/$ITERATIONS [$ITERATION_TIME]"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    
    # Run the test and capture the result
    if "$TEST_SCRIPT" >> "$LOG_FILE" 2>&1; then
        PASSED=$((PASSED + 1))
        STATUS="✅ PASSED"
    else
        FAILED=$((FAILED + 1))
        STATUS="❌ FAILED"
    fi
    
    ITERATION_END=$(date +%s)
    ITERATION_DURATION=$((ITERATION_END - ITERATION_START))
    
    # Calculate remaining time estimate
    AVG_DURATION=$(($(date +%s) - TEST_START_TIME))
    AVG_DURATION=$((AVG_DURATION / i))
    REMAINING_ITERATIONS=$((ITERATIONS - i))
    ESTIMATED_REMAINING_TIME=$((AVG_DURATION * REMAINING_ITERATIONS))
    
    HOURS=$((ESTIMATED_REMAINING_TIME / 3600))
    MINUTES=$(((ESTIMATED_REMAINING_TIME % 3600) / 60))
    
    echo "$STATUS (Duration: ${ITERATION_DURATION}s)"
    echo "   Pass: $PASSED | Fail: $FAILED | ETA: ${HOURS}h ${MINUTES}m"
    echo ""
done

# ============================================================================
# Summary Report
# ============================================================================

TEST_END_TIME=$(date +%s)
TOTAL_DURATION=$((TEST_END_TIME - TEST_START_TIME))

HOURS=$((TOTAL_DURATION / 3600))
MINUTES=$(((TOTAL_DURATION % 3600) / 60))
SECONDS=$((TOTAL_DURATION % 60))

SUCCESS_RATE=$((PASSED * 100 / ITERATIONS))

echo ""
echo "╔════════════════════════════════════════════════════════════════════╗"
echo "║                     STRESS TEST SUMMARY REPORT                     ║"
echo "╚════════════════════════════════════════════════════════════════════╝"
echo ""
echo "Total Iterations:    $ITERATIONS"
echo "✅ Passed:           $PASSED"
echo "❌ Failed:           $FAILED"
echo "⏭️  Skipped:          $SKIPPED"
echo "Success Rate:        $SUCCESS_RATE%"
echo ""
echo "Total Duration:      ${HOURS}h ${MINUTES}m ${SECONDS}s"
echo "Avg Per Iteration:   $((TOTAL_DURATION / ITERATIONS))s"
echo ""
echo "Log File:            $LOG_FILE"
echo ""

if [ $FAILED -eq 0 ]; then
    echo "🎉 All tests passed!"
    exit 0
else
    echo "⚠️  Some tests failed. Check $LOG_FILE for details."
    exit 1
fi
