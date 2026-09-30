<?php
// api/app/Mail/InvitationMail.php
namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The invitation email (Plan D design §3.1): org, role, expiry and the link. The link carries the
 * token, so this mail is the only place it is written; nothing logs it.
 */
class InvitationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $orgName,
        public string $role,
        public string $link,
        public string $expiresOn,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You're invited to {$this->orgName} on Success Meter");
    }

    public function content(): Content
    {
        return new Content(html: 'mail.invitation', text: 'mail.invitation-text');
    }
}
