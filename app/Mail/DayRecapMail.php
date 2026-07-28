<?php

namespace App\Mail;

use App\Models\Competition;
use App\Models\CompetitionDay;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DayRecapMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param array $recap Résultat de DayRecapService::build()
     */
    public function __construct(
        public array $recap,
        public Competition $competition,
        public CompetitionDay $day,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '%s — Jour %d : %d points',
                $this->competition->name,
                $this->day->day_number,
                $this->recap['total']
            ),
        );
    }

    public function content(): Content
    {
        // markdown: et non view: — les composants <x-mail::…> ne sont
        // enregistrés que par le rendu Markdown.
        return new Content(markdown: 'mail.day-recap');
    }
}
