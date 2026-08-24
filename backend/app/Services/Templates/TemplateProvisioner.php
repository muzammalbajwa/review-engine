<?php

namespace App\Services\Templates;

use App\Models\Campaign;
use App\Models\Template;

/**
 * Extracted from TemplateController (which used to own DEFAULT_BODIES and
 * ensureDefaultTemplates() as private members) so the send pipeline can
 * guarantee a compliant template exists without duplicating this copy in
 * a second place — .claude/COMPLIANCE.md's "ship compliant defaults" is
 * exactly what SendReviewRequest depends on for a tenant who has never
 * opened the Templates page: sending must not silently do nothing just
 * because nobody has visited /templates yet.
 */
class TemplateProvisioner
{
    private const STEPS = [1, 2, 3];

    /**
     * Authored compliant by construction (no staff names, no star-rating
     * ask, no incentive, no conditional/gating language, same link for
     * everyone) — stored directly at compliance_status=pass rather than
     * verified through Claude at provisioning time, so a brand-new
     * tenant's default templates don't depend on the compliance checker
     * being reachable at all.
     */
    public const DEFAULT_BODIES = [
        1 => "Hi {name}, thank you for choosing {business_name}! We'd love to hear about your experience — please share a review here: {review_link}",
        2 => "Hi {name}, just a friendly reminder — we'd really appreciate your feedback on your recent experience with {business_name}: {review_link}",
        3 => "Hi {name}, it's been a while since we worked with you at {business_name}. We'd love to hear your thoughts — please leave us a review here: {review_link}",
    ];

    public function ensureDefaults(Campaign $campaign): void
    {
        foreach (self::STEPS as $step) {
            $template = Template::query()->firstOrNew(['campaign_id' => $campaign->id, 'step' => $step]);

            if ($template->exists) {
                continue;
            }

            $template->body = self::DEFAULT_BODIES[$step];
            $template->compliance_status = 'pass';
            $template->compliance_reasons = [];
            $template->save();
        }
    }
}
