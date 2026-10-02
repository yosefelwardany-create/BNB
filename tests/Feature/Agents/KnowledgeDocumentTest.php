<?php

declare(strict_types=1);

namespace Tests\Feature\Agents;

use App\Domain\Agents\Enums\AgentAudience;
use App\Domain\Agents\Services\AgentBriefStore;
use App\Domain\Agents\Services\GuestAgent;
use App\Domain\Agents\Services\OperatorKnowledge;
use App\Domain\Agents\Services\PropertyKnowledge;
use App\Domain\Organization\Models\Organization;
use App\Domain\Properties\Jobs\FetchKnowledgeDocument;
use App\Domain\Properties\Models\Property;
use App\Domain\Properties\Models\PropertyDocument;
use App\Domain\Properties\Services\KnowledgeDocumentFetcher;
use App\Domain\Properties\Services\PropertyService;
use App\Domain\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The knowledge base an agent actually reads.
 *
 * It was a link: a person could open it and the agent could not, so an agent sat
 * answering from ten public facts while the answer was in a document nobody had
 * given it.
 *
 * The claim that matters most here is the one about guests. A house manual
 * routinely contains a door code, and the entire entitlement design is that
 * arrival details reach only a guest with a confirmed, paid booking inside its
 * window. A document pasted wholesale into a guest's prompt would walk around
 * that gate rather than through it — so reaching guests is opt in, off, and
 * warned about.
 */
class KnowledgeDocumentTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $user;

    public function test_a_google_doc_link_is_fetched_from_the_endpoint_that_serves_text(): void
    {
        $fetcher = $this->app->make(KnowledgeDocumentFetcher::class);

        // The share URL serves an application. Storing that would mean keeping a
        // page of JavaScript and calling it a house manual.
        $this->assertSame(
            'https://docs.google.com/document/d/1abcDEF_123/export?format=txt',
            $fetcher->fetchableUrl('https://docs.google.com/document/d/1abcDEF_123/edit?usp=sharing'),
        );

        // Anything else is fetched as given.
        $this->assertSame(
            'https://wiki.example.com/yellow',
            $fetcher->fetchableUrl('https://wiki.example.com/yellow'),
        );
    }

    public function test_saving_a_link_creates_the_document_and_queues_a_read(): void
    {
        Queue::fake();
        $property = $this->property();

        $this->app->make(AgentBriefStore::class)->save($property, [
            'knowledge_base_url' => 'https://docs.google.com/document/d/1abc/edit',
        ]);

        $document = PropertyDocument::query()->where('property_id', $property->getKey())->firstOrFail();

        $this->assertSame(PropertyDocument::KIND_KNOWLEDGE_BASE, $document->kind);
        // Off. Always off. Somebody decides this about a document they have read.
        $this->assertFalse($document->is_guest_safe);

        Queue::assertPushed(FetchKnowledgeDocument::class);
    }

    public function test_the_text_is_read_and_stored_with_the_date_it_was_taken(): void
    {
        $document = $this->fetchedDocument("House manual\n\nCheck-in is from 4pm.\nThe bins go out on Tuesday.");

        $this->assertSame(PropertyDocument::STATUS_OK, $document->status);
        $this->assertStringContainsString('bins go out on Tuesday', (string) $document->content);
        // Dated, because it is a snapshot of a document maintained elsewhere.
        $this->assertNotNull($document->fetched_at);
    }

    public function test_html_comes_back_as_prose_rather_than_markup(): void
    {
        $document = $this->fetchedDocument(
            '<html><head><style>.x{color:red}</style><script>var a=1</script></head>'
            .'<body><h1>House manual</h1><p>Check-in is from 4pm.</p></body></html>',
            contentType: 'text/html',
        );

        $this->assertStringContainsString('Check-in is from 4pm.', (string) $document->content);
        // Scripts and styles are not prose, and leaving them in puts a page of
        // CSS in the middle of a house manual.
        $this->assertStringNotContainsString('var a=1', (string) $document->content);
        $this->assertStringNotContainsString('color:red', (string) $document->content);
    }

    public function test_an_unshared_document_says_what_to_do_about_it(): void
    {
        $document = $this->fetchedDocument('', status: 403);

        $this->assertSame(PropertyDocument::STATUS_FORBIDDEN, $document->status);
        // "403 Forbidden" tells nobody which three clicks fix it.
        $this->assertStringContainsString('Anyone with the link', (string) $document->failure);
    }

    public function test_a_document_that_stops_working_keeps_answering_from_the_last_copy(): void
    {
        $property = $this->property();

        /*
         * One stub whose answer changes, rather than two fakes.
         *
         * `Http::fake()` merges its stubs and the first match wins, so calling
         * it again with the same pattern never takes effect.
         */
        $status = 200;

        Http::fake(['*' => function () use (&$status) {
            return Http::response($status === 200 ? 'Check-in is from 4pm.' : '', $status);
        }]);

        $document = PropertyDocument::query()->create([
            'organization_id' => $property->organization_id,
            'property_id' => $property->getKey(),
            'kind' => PropertyDocument::KIND_KNOWLEDGE_BASE,
            'url' => 'https://docs.example.com/manual',
        ]);

        $document = $this->app->make(KnowledgeDocumentFetcher::class)->refresh($document);
        $this->assertSame(PropertyDocument::STATUS_OK, $document->status);

        $status = 403;
        $document = $this->app->make(KnowledgeDocumentFetcher::class)->refresh($document);

        $this->assertSame(PropertyDocument::STATUS_FORBIDDEN, $document->status);
        // Going silent would be worse than answering from a copy with a date on
        // it, which is what the agent is told it has.
        $this->assertStringContainsString('Check-in is from 4pm.', (string) $document->content);
    }

    public function test_a_guest_is_not_shown_the_document_until_somebody_says_so(): void
    {
        $property = $this->property();
        $document = $this->fetchedDocument('The spare key is under the third plant pot.', $property);

        $knowledge = $this->app->make(PropertyKnowledge::class);

        // Off by default: the gate that keeps a door code from an unbooked
        // guest is not something a house manual gets to walk around.
        $this->assertSame([], $knowledge->public($property->fresh())['knowledge'] ?? []);

        $document->forceFill(['is_guest_safe' => true])->save();

        $shared = $knowledge->public($property->fresh())['knowledge'] ?? [];

        $this->assertCount(1, $shared);
        $this->assertStringContainsString('third plant pot', $shared[0]['text']);
    }

    public function test_the_manager_reads_it_whether_or_not_guests_may(): void
    {
        $property = $this->property();
        $this->fetchedDocument('Owner takes 70% after cleaning.', $property);

        $facts = $this->app->make(OperatorKnowledge::class)->about($property->fresh(), $this->user);

        // The guest-safe flag governs what reaches a guest. Withholding the
        // house manual from the manager would make this agent useless for the
        // thing it is most often asked.
        $this->assertCount(1, $facts['facts']['knowledge']);
        $this->assertStringContainsString('70%', $facts['facts']['knowledge'][0]['text']);
    }

    public function test_the_screen_is_warned_when_a_document_holds_the_property_secrets(): void
    {
        $property = $this->property();
        $this->fetchedDocument('The door code is 4821 and the wifi password is hunter2.', $property);

        $this->getJson("/api/v1/properties/{$property->getKey()}/agent/documents")
            ->assertOk()
            ->assertJsonPath('data.0.contains_secrets', ['door code', 'wifi password']);
    }

    public function test_sharing_with_guests_is_its_own_deliberate_action(): void
    {
        $property = $this->property();
        $document = $this->fetchedDocument('Check-in is from 4pm.', $property);

        $this->patchJson(
            "/api/v1/properties/{$property->getKey()}/agent/documents/{$document->getKey()}/sharing",
            ['is_guest_safe' => true],
        )->assertOk()->assertJsonPath('data.is_guest_safe', true);

        $this->assertTrue($document->fresh()->is_guest_safe);
    }

    public function test_a_document_belonging_to_another_property_cannot_be_shared_through_this_one(): void
    {
        $property = $this->property();
        $document = $this->fetchedDocument('Check-in is from 4pm.', $property);

        $other = $this->app->make(PropertyService::class)->create([
            'name' => 'Grey Room',
            'property_type' => 'apartment',
            'city' => 'Toronto',
            'country_code' => 'CA',
            'max_occupancy' => 2,
            'base_rate' => 5800,
        ]);

        $this->patchJson(
            "/api/v1/properties/{$other->getKey()}/agent/documents/{$document->getKey()}/sharing",
            ['is_guest_safe' => true],
        )->assertStatus(404);

        $this->assertFalse($document->fresh()->is_guest_safe);
    }

    public function test_changing_the_link_throws_away_the_old_text(): void
    {
        Queue::fake();
        $property = $this->property();
        $document = $this->fetchedDocument('The old house manual.', $property);

        $this->app->make(AgentBriefStore::class)->save($property, [
            'knowledge_base_url' => 'https://docs.google.com/document/d/somewhere-else/edit',
        ]);

        $document->refresh();

        // A different document is a different document. Keeping the old text
        // against the new address would have the agent quoting the wrong house.
        $this->assertNull($document->content);
        $this->assertSame(PropertyDocument::STATUS_PENDING, $document->status);
    }

    public function test_clearing_the_link_removes_the_copy(): void
    {
        Queue::fake();
        $property = $this->property();
        $this->fetchedDocument('The house manual.', $property);

        $this->app->make(AgentBriefStore::class)->save($property, ['knowledge_base_url' => null]);

        // A copy of a document nobody points at any more is a copy that gets
        // quoted at a guest next week with no way to correct it.
        $this->assertSame(0, PropertyDocument::query()->where('property_id', $property->getKey())->count());
    }

    public function test_the_agent_answers_from_what_the_document_says(): void
    {
        $property = $this->property();
        $document = $this->fetchedDocument('The lift is on the left of the lobby.', $property);
        $document->forceFill(['is_guest_safe' => true])->save();

        $answer = $this->app->make(GuestAgent::class)->answer(
            property: $property->fresh(),
            question: 'Is there a lift?',
            audience: AgentAudience::Guest,
        );

        // The whole point: the document is among the facts the agent was given,
        // rather than a link it could not open.
        $this->assertContains('knowledge', $answer->usedFacts);
    }

    /**
     * A document already fetched, with whatever the server returned.
     */
    private function fetchedDocument(
        string $body,
        ?Property $property = null,
        int $status = 200,
        string $contentType = 'text/plain',
    ): PropertyDocument {
        $property ??= $this->property();

        Http::fake(['*' => Http::response($body, $status, ['Content-Type' => $contentType])]);

        $document = PropertyDocument::query()->create([
            'organization_id' => $property->organization_id,
            'property_id' => $property->getKey(),
            'kind' => PropertyDocument::KIND_KNOWLEDGE_BASE,
            'url' => 'https://docs.example.com/manual',
        ]);

        return $this->app->make(KnowledgeDocumentFetcher::class)->refresh($document);
    }

    private function property(): Property
    {
        if (! isset($this->organization)) {
            $this->organization = $this->createOrganization();
            $this->user = $this->createUser($this->organization);
            $this->actingAsUser($this->user, $this->organization);
        }

        return $this->app->make(PropertyService::class)->create([
            'name' => 'Blue Room',
            'property_type' => 'apartment',
            'address_line_1' => '12 Lake Shore',
            'postal_code' => 'M8V 1A1',
            'city' => 'Toronto',
            'country_code' => 'CA',
            'max_occupancy' => 2,
            'base_rate' => 5800,
            'door_code' => '4821',
            'wifi_password' => 'hunter2',
        ]);
    }
}
