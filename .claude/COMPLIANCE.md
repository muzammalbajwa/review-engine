# COMPLIANCE — Google review rules, enforced in code

## The law of this product (Google policy, April 2026 + FTC 2024 rule)
1. NO review gating. Every customer gets the SAME review link regardless of
   sentiment. There is NO code branch that routes by happy/unhappy. Ever.
2. NO staff-name requests in templates.
3. NO incentives (discounts/gifts/points) offered for reviews.
4. NO on-premises/kiosk collection prompts.
5. Requests go to ALL customers equally.
6. Drip slowly (2–3 per 20 min, business hours) — blasting trips Google's
   same-device/near-identical-phrasing filters.

## The compliance checker (blocks template save)
Before a template is saved, call the Claude API to classify the text.
BLOCK and return a friendly fix if it contains:
- a request to name a staff member
- a request for a specific star rating ("give us 5 stars")
- any incentive tied to reviewing
- conditional/gating language ("if you're happy...", "if you had a good
  experience, click here")
Return: {status: pass|block, reasons: [...], suggested_rewrite: "..."}
Store compliance_status on the template. status=block cannot be activated.

## Ship compliant defaults
Default templates are pre-written, compliant, effective. Editing is a
power-user action. Most tenants never touch them.

## Sell this
"Compliance-checked templates — we won't let you get your profile banned."
Surface Google's PolicyViolation status on rejected auto-replies so the
customer sees when Google refused a reply. No competitor shows this.
