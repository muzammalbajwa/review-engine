<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent by SendReviewRequest. Not queued itself (no ShouldQueue) — the job
 * that builds this is already the queued unit of work; a second queue
 * layer here would just add latency and a second place retries could
 * happen.
 *
 * The body is the tenant's own compliance-passed template
 * (.claude/CLAUDE.md golden rule #4), already interpolated by the caller
 * — this class does no merge-field logic itself, so there's exactly one
 * place ({name}/{business_name}/{review_link}) that substitution happens,
 * not two.
 */
class ReviewRequestMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $renderedBody,
        public readonly string $fromEmail,
        public readonly string $fromName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->fromEmail, $this->fromName),
            subject: "A message from {$this->fromName}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.review-request',
            with: ['renderedBody' => $this->renderedBody],
        );
    }
}
