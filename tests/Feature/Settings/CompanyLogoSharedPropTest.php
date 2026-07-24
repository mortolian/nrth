<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CompanyLogoSharedPropTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_logo_url_is_null_when_team_has_no_logo(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('company_logo_url', null)
            );
    }

    public function test_company_logo_url_is_shared_when_team_has_logo(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $this->assertNotNull($team);

        $team->addMedia(UploadedFile::fake()->image('logo.png', 120, 120))
            ->toMediaCollection('logo');

        $expected = $team->fresh()->getFirstMedia('logo')?->getUrl();
        $this->assertNotEmpty($expected);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('company_logo_url', $expected)
            );
    }
}
