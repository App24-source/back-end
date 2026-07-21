<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ResetCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public $code;  // Add a property to hold the reset code
    public $email; // Add a property to hold the user's email

    /**
     * Create a new message instance.
     *
     * @param string $code
     * @param string $email
     */
    public function __construct(string $code, string $email)
    {
        $this->code = $code;  // Assign the reset code
        $this->email = $email; // Assign the user's email
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Password Reset Code', // Update the subject as needed
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.reset_code', // Ensure this matches your Blade view file
            with: [
                'code' => $this->code,     // Pass the code to the view
                'email' => $this->email,   // You may want to pass the email too
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}