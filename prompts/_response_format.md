## Response Format

You MUST respond with valid JSON in this exact structure:

```json
{
  "alt_en": "string (the generated alt text in English)",
  "confidence": 0.0–1.0 (numeric confidence score),
  "policy_compliant": true|false (boolean compliance indicator),
  "tags": ["array", "of", "strings"],
  "violations": ["array", "of", "violation", "codes"]
}
```

**Requirements:**
- Return ONLY valid JSON (no markdown, no explanations, no extra text)
- All five fields are required
- `alt_en` must be a non-empty string
- `confidence` must be a number between 0.0 and 1.0
- `policy_compliant` must be boolean (true or false)
- `tags` and `violations` must be arrays (can be empty: [])

**Example Valid Response:**
```json
{
  "alt_en": "Epson EcoTank L3560 A4 multifunction ink tank printer in black",
  "confidence": 0.92,
  "policy_compliant": true,
  "tags": ["printer", "ecotank", "multifunction"],
  "violations": []
}
```
