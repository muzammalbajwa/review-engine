<?php

use App\Notifications\ContactMessageReceived;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;

function validContactPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Jane Prospect',
        'email' => 'jane@example.com',
        'message' => 'Hi — do you support salons, not just trades?',
    ], $overrides);
}

test('the contact form requires a name, email, and message', function () {
    $response = $this->postJson('/api/v1/contact', []);

    $response->assertStatus(422);
    expect($response->json('fields.name'))->not->toBeNull();
    expect($response->json('fields.email'))->not->toBeNull();
    expect($response->json('fields.message'))->not->toBeNull();
});

test('a valid contact message sends to the configured support inbox with no auth at all', function () {
    Config::set('services.support.inbox', 'support@reviewengine24.com');
    Notification::fake();

    $response = $this->postJson('/api/v1/contact', validContactPayload());

    $response->assertCreated();
    expect($response->json('data.message'))->toBe("Thanks — we'll get back to you soon.");

    Notification::assertSentOnDemand(
        ContactMessageReceived::class,
        function (ContactMessageReceived $notification, array $channels, object $notifiable) {
            return $notifiable->routes['mail'] === 'support@reviewengine24.com';
        }
    );
});

test('the reply-to on the sent mail is the visitor\'s own address, not ours', function () {
    Config::set('services.support.inbox', 'support@reviewengine24.com');
    Notification::fake();

    $this->postJson('/api/v1/contact', validContactPayload(['name' => 'Jane Prospect', 'email' => 'jane@example.com']))
        ->assertCreated();

    Notification::assertSentOnDemand(
        ContactMessageReceived::class,
        function (ContactMessageReceived $notification, array $channels, object $notifiable) {
            $mail = $notification->toMail($notifiable);

            expect($mail->replyTo)->toHaveCount(1);
            expect($mail->replyTo[0][0])->toBe('jane@example.com');

            return true;
        }
    );
});

test('a filled honeypot field returns the same success response but never actually sends', function () {
    Config::set('services.support.inbox', 'support@reviewengine24.com');
    Notification::fake();

    $response = $this->postJson('/api/v1/contact', validContactPayload(['company' => 'Definitely A Bot LLC']));

    $response->assertCreated();
    expect($response->json('data.message'))->toBe("Thanks — we'll get back to you soon.");
    Notification::assertNothingSent();
});

test('a missing support inbox configuration fails the request closed — never a false success', function () {
    Config::set('services.support.inbox', null);
    Notification::fake();

    $response = $this->postJson('/api/v1/contact', validContactPayload());

    $response->assertStatus(502);
    expect($response->json('error'))->toBe('send_failed');
    Notification::assertNothingSent();
});

test('the contact endpoint is rate-limited per ip and cannot be spammed', function () {
    Config::set('services.support.inbox', 'support@reviewengine24.com');
    Notification::fake();

    for ($i = 0; $i < 3; $i++) {
        $response = $this->postJson('/api/v1/contact', validContactPayload(['message' => "Message {$i}"]));
        expect($response->status())->not->toBe(429);
    }

    $response = $this->postJson('/api/v1/contact', validContactPayload(['message' => 'One too many']));

    $response->assertStatus(429);
    expect($response->json('error'))->toBe('rate_limited');
});
