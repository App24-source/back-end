<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpImap\Mailbox;
use Illuminate\Support\Facades\Http;

class ProcessBounces extends Command
{
    protected $signature = 'bounces:process';
    protected $description = 'Process bounce emails from MAILER-DAEMON';

    private $apiUrl = 'https://1azsq21dfsffs45ytuiooihgzgz.flink.ma/api/webhooks/email-bounce';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🚀 Starting bounce processing...');

        try {
            // Configuration depuis le .env
            $server = '{'.env('MAIL_HOST').':993/imap/ssl}INBOX';
            $username = env('MAIL_USERNAME');
            $password = env('MAIL_PASSWORD');

            $mailbox = new Mailbox($server, $username, $password);
            $mailbox->setAttachmentsIgnore(true);

            // Chercher les emails
            $mailsIds = $mailbox->searchMailbox(
                'FROM "MAILER-DAEMON@mail.infomaniak.ch" UNSEEN'
            );

            if (empty($mailsIds)) {
                $this->info('✅ No new bounces found.');
                return;
            }

            $this->info('📨 Found ' . count($mailsIds) . ' bounce(s) to process');

            foreach ($mailsIds as $mailId) {
                $this->processEmail($mailbox, $mailId);
            }

            $this->info('🎉 Bounce processing completed!');

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
        }
    }

    private function processEmail($mailbox, $mailId)
{
    try {
        $mail = $mailbox->getMail($mailId);
        
        // DEBUG: Voir toutes les propriétés disponibles
        $this->info("DEBUG - Mail properties: " . json_encode([
            'from' => $mail->fromAddress ?? null,
            'to' => $mail->toString ?? null,
            'subject' => $mail->subject ?? null,
            'date' => $mail->date ?? null,
            'has_text' => !empty($mail->textPlain),
            'has_html' => !empty($mail->textHtml),
            'headers' => $mail->headers ?? null
        ]));

        // Construction du contenu avec fallbacks
        $emailContent = "From: " . ($mail->fromAddress ?? $mail->headers->from ?? 'Unknown') . "\n";
        $emailContent .= "To: " . ($mail->toString ?? $mail->headers->to ?? 'Unknown') . "\n";
        $emailContent .= "Subject: " . ($mail->subject ?? 'No Subject') . "\n";
        $emailContent .= "Date: " . ($mail->date ?? now()->toString()) . "\n\n";
        
        // Contenu du message
        if (!empty($mail->textPlain)) {
            $emailContent .= "Content:\n" . $mail->textPlain;
        } elseif (!empty($mail->textHtml)) {
            $emailContent .= "Content:\n" . strip_tags($mail->textHtml);
        } else {
            $emailContent .= "Content: No message content available";
        }

        $this->info("Processing email: " . ($mail->subject ?? 'No Subject'));

        // Envoi à l'API
        $response = Http::timeout(30)
            ->withHeaders(['Content-Type' => 'text/plain'])
            // ->withToken(env('BOUNCE_WEBHOOK_TOKEN'))
            ->post($this->apiUrl . '?token=' . env('BOUNCE_WEBHOOK_TOKEN'), $emailContent);

        if ($response->successful()) {
            $mailbox->markMailAsRead($mailId);
            $this->info("✅ Success: " . ($mail->subject ?? 'No Subject'));
        } else {
            $this->error("❌ Failed: " . $response->status() . " - " . $response->body());
        }

    } catch (\Exception $e) {
        $this->error("❌ Error processing email {$mailId}: " . $e->getMessage());
        // Log plus détaillé pour debugging
        Log::error("Bounce processing error", [
            'mail_id' => $mailId,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}
}
