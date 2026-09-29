<?php
// api/tests/Feature/Hub/HubClientCommandTest.php

namespace Tests\Feature\Hub;

use App\Hub\Identity\FirstPartyClient;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

class HubClientCommandTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    public function test_registers_a_web_client_that_can_use_client_credentials(): void
    {
        $exit = Artisan::call('hub:client', [
            'tool' => 'web',
            'redirect' => 'http://localhost:8787/auth/callback',
            'post_logout_redirect' => 'http://localhost:5173/',
        ]);
        $this->assertSame(0, $exit);

        $output = Artisan::output(); // reading drains the buffer, so read once
        preg_match('/HUB_CLIENT_ID=(\S+)/', $output, $id);
        preg_match('/HUB_CLIENT_SECRET=(\S+)/', $output, $secret);
        $client = FirstPartyClient::findOrFail($id[1]);

        $this->assertSame('web', $client->hub_tool);
        $this->assertSame(['http://localhost:8787/auth/callback'], $client->redirect_uris);
        $this->assertSame(['http://localhost:5173/'], $client->post_logout_redirect_uris);
        $this->assertNotSame($secret[1], $client->secret, 'only a hash is stored');

        $this->post('/oauth/token', [
            'grant_type' => 'client_credentials', 'client_id' => $client->id, 'client_secret' => $secret[1],
        ])->assertOk();
    }

    public function test_rejects_unknown_tools_and_bad_urls(): void
    {
        $this->artisan('hub:client', ['tool' => 'sms', 'redirect' => 'https://a.test/cb', 'post_logout_redirect' => 'https://a.test/'])->assertFailed();
        $this->artisan('hub:client', ['tool' => 'web', 'redirect' => 'javascript:x', 'post_logout_redirect' => 'https://a.test/'])->assertFailed();
    }
}
