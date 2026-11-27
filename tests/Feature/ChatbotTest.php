<?php

namespace Tests\Feature;

use App\Services\Ontology\DestinationRepository;
use App\Services\Ontology\DestinationSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ChatbotTest extends TestCase
{
    public function test_chatbot_uses_local_search_when_the_api_key_is_missing(): void
    {
        config()->set('services.gemini.key', '');

        $response = $this->postJson('/chat', [
            'message' => 'Buatkan itinerary 3 hari ke Danau Toba',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['reply', 'destinations']);
        $this->assertStringContainsString('dataset lokal', $response->json('reply'));
        Http::assertNothingSent();
    }

    public function test_chatbot_validates_message_length_and_history_shape(): void
    {
        config()->set('services.gemini.key', 'test-key');

        $this->postJson('/chat', ['message' => str_repeat('a', 801)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('message');

        $this->postJson('/chat', [
            'message' => 'Halo',
            'history' => [['role' => 'system', 'text' => 'ignored']],
        ])->assertStatus(422);

        $this->postJson('/chat', [
            'message' => 'Halo',
            'history' => array_fill(0, 21, ['role' => 'user', 'text' => 'Hai']),
        ])->assertStatus(422)
            ->assertJsonValidationErrors('history');

        $this->postJson('/chat', [
            'message' => 'Halo',
            'history' => [['role' => 'user', 'text' => str_repeat('a', 4001)]],
        ])->assertStatus(422)
            ->assertJsonValidationErrors('history.0.text');
    }

    public function test_chatbot_sends_the_key_in_a_header_and_forwards_conversation_history(): void
    {
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'gemini-3.5-flash-lite');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Danau Toba adalah danau vulkanik.']]]],
                ],
            ]),
        ]);

        $history = [];
        for ($index = 0; $index < 20; $index++) {
            $history[] = [
                'role' => $index % 2 === 0 ? 'user' : 'model',
                'text' => 'Riwayat '.$index,
            ];
        }

        $response = $this->postJson('/chat', [
            'message' => 'Buatkan itinerary 3 hari ke Danau Toba',
            'history' => $history,
        ]);
        Http::assertSentCount(1);

        $response->assertOk()
            ->assertJsonPath('reply', 'Danau Toba adalah danau vulkanik.')
            ->assertJsonStructure([
                'reply',
                'destinations' => [
                    '*' => ['id', 'name', 'latitude', 'longitude', 'location'],
                ],
            ]);

        Http::assertSent(function (Request $request): bool {
            $payload = $request->data();

            return $request->url() === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.5-flash-lite:generateContent'
                && $request->hasHeader('x-goog-api-key', 'test-key')
                && $payload['contents'][0]['role'] === 'user'
                && $payload['contents'][0]['parts'][0]['text'] === 'Riwayat 14'
                && $payload['contents'][5]['role'] === 'model'
                && $payload['contents'][5]['parts'][0]['text'] === 'Riwayat 19'
                && count($payload['contents']) === 7
                && $payload['contents'][6]['parts'][0]['text'] === 'Buatkan itinerary 3 hari ke Danau Toba'
                && str_contains($payload['systemInstruction']['parts'][0]['text'], 'KONTEKS ONTOLOGI')
                && str_contains($payload['systemInstruction']['parts'][0]['text'], 'Danau Toba')
                && ! str_contains($payload['systemInstruction']['parts'][0]['text'], 'Destinasi dengan rating tertinggi')
                && $payload['generationConfig']['temperature'] === 0.7;
        });
    }

    public function test_valid_gemini_replies_are_cached_for_thirty_minutes(): void
    {
        config()->set('services.gemini.key', 'test-key');
        Cache::flush();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Keduanya cocok untuk liburan keluarga.']]]],
                ],
            ]),
        ]);

        $firstReply = $this->postJson('/chat', [
            'message' => 'Bandingkan Danau Toba dan Berastagi untuk keluarga?',
        ])->assertOk()->json('reply');
        $secondReply = $this->postJson('/chat', [
            'message' => 'bandingkan danau toba dan berastagi untuk keluarga?',
        ])->assertOk()->json('reply');

        $this->assertSame($firstReply, $secondReply);
        Http::assertSentCount(1);

        $this->travel(31)->minutes();
        $this->postJson('/chat', [
            'message' => 'bandingkan danau toba dan berastagi untuk keluarga?',
        ])->assertOk();

        Http::assertSentCount(2);
    }

    public function test_unexpected_ontology_failures_return_a_safe_chat_reply(): void
    {
        config()->set('services.gemini.key', '');
        $source = \Mockery::mock(DestinationSource::class);
        $source->shouldReceive('all')->andThrow(new \RuntimeException('private ontology detail'));
        app()->instance(DestinationRepository::class, new DestinationRepository($source));

        $response = $this->postJson('/chat', [
            'message' => 'Tolong carikan wisata alam di Karo',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['reply', 'destinations'])
            ->assertJsonPath('destinations', []);
        $this->assertStringContainsString('/destinations', $response->json('reply'));
        $this->assertStringContainsString('/map', $response->json('reply'));
        $this->assertStringNotContainsString('private ontology detail', $response->getContent());
    }

    public function test_chat_route_uses_the_response_contract(): void
    {
        config()->set('services.gemini.key', '');

        $this->postJson('/chat', ['message' => 'Halo'])
            ->assertOk()
            ->assertJsonStructure(['reply', 'destinations'])
            ->assertJsonPath('destinations', []);
    }

    public function test_chatbot_uses_local_search_when_the_upstream_service_fails(): void
    {
        config()->set('services.gemini.key', 'test-key');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'secret upstream detail'], 500),
        ]);

        $response = $this->postJson('/chat', [
            'message' => 'Buatkan itinerary 3 hari ke Danau Toba',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['reply', 'destinations']);
        $this->assertStringContainsString('dataset lokal', $response->json('reply'));
        $this->assertStringNotContainsString('secret upstream detail', $response->getContent());
    }

    public function test_chatbot_retries_rate_limited_requests_then_uses_local_search(): void
    {
        config()->set('services.gemini.key', 'test-key');

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::sequence()
                ->push(['error' => 'rate limited'], 429)
                ->push(['error' => 'service unavailable'], 503),
        ]);

        $response = $this->postJson('/chat', [
            'message' => 'Buatkan itinerary 3 hari ke Danau Toba',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['reply', 'destinations']);
        $this->assertStringContainsString('dataset lokal', $response->json('reply'));
        Http::assertSentCount(2);
    }

    public function test_structured_local_questions_do_not_call_gemini_and_return_map_destinations(): void
    {
        config()->set('services.gemini.key', '');

        $response = $this->postJson('/chat', [
            'message' => 'wisata alam di Kabupaten Karo',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'reply',
                'destinations' => [
                    '*' => ['id', 'name', 'latitude', 'longitude', 'location'],
                ],
            ]);

        $this->assertNotEmpty($response->json('destinations'));
        $this->assertLessThanOrEqual(5, count($response->json('destinations')));
        foreach ($response->json('destinations') as $destination) {
            $this->assertIsNumeric($destination['latitude']);
            $this->assertGreaterThanOrEqual(-90, $destination['latitude']);
            $this->assertLessThanOrEqual(90, $destination['latitude']);
            $this->assertIsNumeric($destination['longitude']);
            $this->assertGreaterThanOrEqual(-180, $destination['longitude']);
            $this->assertLessThanOrEqual(180, $destination['longitude']);
        }

        Http::assertNothingSent();
    }

    public function test_local_recommendations_respect_digit_and_word_number_limits(): void
    {
        config()->set('services.gemini.key', '');
        $bahariCount = app(DestinationRepository::class)->search(['jenis' => 'Bahari'])
            ->filter(fn ($destination): bool => $destination->hasCoordinates())
            ->count();
        $this->assertGreaterThanOrEqual(3, $bahariCount);

        foreach ([
            ['message' => '3 tempat wisata bahari', 'count' => 3],
            ['message' => 'sebutkan lima wisata alam', 'count' => 5],
            ['message' => '10 wisata alam', 'count' => 10],
            ['message' => 'wisata alam harga 10 rb', 'count' => 5],
            ['message' => 'wisata alam rating 4 harga 10 rb 3 jam 2 km', 'count' => 5],
            ['message' => 'kasih 2 rekomendasi wisata alam di Kabupaten Karo', 'count' => 2],
            ['message' => 'kasih 2 wisata alam', 'count' => 2],
            ['message' => 'cukup 3 wisata alam', 'count' => 3],
            ['message' => 'rekomendasiin 2 wisata alam', 'count' => 2],
        ] as $case) {
            $response = $this->postJson('/chat', ['message' => $case['message']]);

            $response->assertOk();
            $this->assertCount(
                $case['count'],
                $response->json('destinations'),
                'Permintaan jumlah tidak dipenuhi: '.$case['message'].'; '.$response->json('reply'),
            );
            $this->assertStringContainsString('**'.$case['count'], $response->json('reply'));
        }

        Http::assertNothingSent();
    }

    public function test_filtered_recommendation_candidates_and_limit_are_sent_to_gemini(): void
    {
        config()->set('services.gemini.key', 'test-key');
        $promptedNames = [];
        Http::fake(function (Request $request) use (&$promptedNames) {
            $instruction = $request->data()['systemInstruction']['parts'][0]['text'];
            preg_match_all('/^- (.+?) \(ID [^)]+\)\R  Nama alternatif:/mu', $instruction, $matches);
            $promptedNames = $matches[1] ?? [];

            return Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Pilihan yang cocok: '.implode(' dan ', $promptedNames).'.']]]],
                ],
            ]);
        });

        $response = $this->postJson('/chat', ['message' => 'kasih 2 wisata alam'])->assertOk();

        $this->assertCount(2, $response->json('destinations'));
        $this->assertSame(
            $promptedNames,
            collect($response->json('destinations'))->pluck('name')->all(),
        );
        Http::assertSent(fn (Request $request): bool => str_contains(
            $request->data()['systemInstruction']['parts'][0]['text'],
            'tepat 2 destinasi berbeda',
        ) && str_contains($request->data()['systemInstruction']['parts'][0]['text'], 'Instagram:')
            && str_contains($request->data()['systemInstruction']['parts'][0]['text'], 'Jam buka:')
            && str_contains($request->data()['systemInstruction']['parts'][0]['text'], 'Alamat:')
            && str_contains($request->data()['systemInstruction']['parts'][0]['text'], 'Fasilitas:')
            && str_contains($request->data()['systemInstruction']['parts'][0]['text'], 'Aktivitas:')
            && str_contains($request->data()['systemInstruction']['parts'][0]['text'], 'Deskripsi:')
            && $request->data()['generationConfig']['temperature'] === 0.7);
    }

    public function test_activity_filter_matches_ontology_activity_labels(): void
    {
        config()->set('services.gemini.key', '');
        $repository = app(DestinationRepository::class);
        $activity = $repository->filterOptions()['activities'][0];

        $response = $this->postJson('/chat', [
            'message' => '2 destinasi untuk '.$activity,
        ]);

        $response->assertOk();
        $this->assertCount(2, $response->json('destinations'));
        $destinationsById = $repository->all()->keyBy('id');
        $attributeTerms = mb_strtolower($activity) === 'belajar'
            ? ['belajar', 'mempelajari', 'edukasi']
            : [mb_strtolower($activity)];
        foreach ($response->json('destinations') as $destination) {
            $record = $destinationsById[$destination['id']];
            $searchableAttributes = mb_strtolower(implode(' ', [
                ...$record->activities,
                ...$record->facilities,
                ...$record->keywords,
                $record->shortDescription,
                $record->fullDescription,
            ]));

            $this->assertTrue(collect($attributeTerms)->contains(
                fn (string $term): bool => str_contains($searchableAttributes, $term),
            ));
        }

        $facility = $repository->filterOptions()['facilities'][0];

        $facilityResponse = $this->postJson('/chat', [
            'message' => '2 destinasi dengan '.$facility,
        ]);

        $facilityResponse->assertOk();
        $this->assertCount(2, $facilityResponse->json('destinations'));
        $facilityTerms = mb_strtolower($facility) === 'akses kursi roda'
            ? ['akses kursi roda', 'kursi roda']
            : [mb_strtolower($facility)];
        foreach ($facilityResponse->json('destinations') as $destination) {
            $record = $destinationsById[$destination['id']];
            $searchableAttributes = mb_strtolower(implode(' ', [
                ...$record->activities,
                ...$record->facilities,
                ...$record->keywords,
                $record->shortDescription,
                $record->fullDescription,
            ]));

            $this->assertTrue(collect($facilityTerms)->contains(
                fn (string $term): bool => str_contains($searchableAttributes, $term),
            ));
        }
        Http::assertNothingSent();
    }

    public function test_lowest_rating_recommendations_are_ascending_and_labeled_correctly(): void
    {
        config()->set('services.gemini.key', '');
        $repository = app(DestinationRepository::class);

        $response = $this->postJson('/chat', [
            'message' => 'berikan 3 wisata dengan rating terendah',
        ])->assertOk();

        $this->assertCount(3, $response->json('destinations'));
        $ratingsById = $repository->all()->pluck('rating', 'id');
        $ratings = collect($response->json('destinations'))
            ->map(fn (array $destination): ?float => $ratingsById[$destination['id']] ?? null)
            ->all();

        $this->assertNotContains(null, $ratings);
        $this->assertSame($ratings, collect($ratings)->sort()->values()->all());
        $this->assertStringContainsString('rating terendah', mb_strtolower($response->json('reply')));
        $this->assertStringNotContainsString('rating tertinggi', mb_strtolower($response->json('reply')));
    }

    public function test_camping_recommendations_only_return_destinations_with_camping_attributes(): void
    {
        config()->set('services.gemini.key', '');
        $repository = app(DestinationRepository::class);

        $response = $this->postJson('/chat', [
            'message' => 'kasih aku 3 tempat wisata yang bisa camping',
        ])->assertOk();

        $this->assertCount(3, $response->json('destinations'));
        $destinationsById = $repository->all()->keyBy('id');
        foreach ($response->json('destinations') as $destination) {
            $record = $destinationsById[$destination['id']];
            $attributes = mb_strtolower(implode(' ', [
                ...$record->activities,
                ...$record->facilities,
                ...$record->keywords,
                $record->shortDescription,
                $record->fullDescription,
            ]));

            $this->assertTrue(
                str_contains($attributes, 'camping') || str_contains($attributes, 'berkemah'),
                $record->name.' tidak memiliki atribut camping.',
            );
        }
    }

    public function test_local_instagram_answers_use_the_dataset_and_explain_missing_accounts(): void
    {
        config()->set('services.gemini.key', '');
        $destinations = app(DestinationRepository::class)->all();
        $destinationWithInstagram = $destinations->first(
            fn ($destination): bool => $destination->instagram !== '',
        );
        $destinationWithoutInstagram = $destinations->first(
            fn ($destination): bool => $destination->instagram === '',
        );

        $this->assertNotNull($destinationWithInstagram);
        $this->assertNotNull($destinationWithoutInstagram);

        $instagramResponse = $this->postJson('/chat', [
            'message' => 'Instagram '.$destinationWithInstagram->name,
        ])->assertOk()->assertJsonStructure(['reply', 'destinations']);
        $this->assertStringContainsString(
            $destinationWithInstagram->instagram,
            $instagramResponse->json('reply'),
        );

        $missingInstagramResponse = $this->postJson('/chat', [
            'message' => 'akun instagram '.$destinationWithoutInstagram->name,
        ])->assertOk();
        $this->assertStringContainsString(
            'belum mencantumkan akun Instagram',
            $missingInstagramResponse->json('reply'),
        );

        Http::assertNothingSent();
    }

    public function test_food_activity_search_does_not_force_the_culinary_category(): void
    {
        config()->set('services.gemini.key', '');
        $repository = app(DestinationRepository::class);
        $destinationsById = $repository->all()->keyBy('id');

        $response = $this->postJson('/chat', [
            'message' => 'kasih 3 wisata yang bisa menikmati makanan',
        ])->assertOk();

        $this->assertCount(3, $response->json('destinations'));
        $types = collect($response->json('destinations'))
            ->map(fn (array $destination): array => $destinationsById[$destination['id']]->types)
            ->flatten()
            ->unique()
            ->all();
        $this->assertNotEmpty(array_diff($types, ['Kuliner']));
        Http::assertNothingSent();
    }

    public function test_price_sorting_supports_cheapest_and_most_expensive_destinations(): void
    {
        config()->set('services.gemini.key', '');
        $repository = app(DestinationRepository::class);
        $destinationsById = $repository->all()->keyBy('id');

        foreach ([
            ['message' => 'berikan 3 destinasi termurah', 'ascending' => true],
            ['message' => 'berikan 3 destinasi termahal', 'ascending' => false],
        ] as $case) {
            $response = $this->postJson('/chat', ['message' => $case['message']])->assertOk();
            $this->assertCount(3, $response->json('destinations'));
            $prices = collect($response->json('destinations'))
                ->map(fn (array $destination): ?float => $destinationsById[$destination['id']]->lowestPrice())
                ->all();

            if (! $case['ascending']) {
                $this->assertTrue(collect($prices)->every(fn (?float $price): bool => $price !== null && $price > 0.0));
                $this->assertSame($prices, collect($prices)->sortDesc()->values()->all());
            } else {
                $this->assertSame(
                    $prices,
                    collect($prices)->sortBy(fn (?float $price): float => $price ?? PHP_FLOAT_MAX)->values()->all(),
                );
            }
        }
    }

    public function test_narrative_comparison_is_forwarded_to_gemini_with_ontology_context(): void
    {
        config()->set('services.gemini.key', 'test-key');
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Keduanya cocok untuk liburan keluarga.']]]],
                ],
            ]),
        ]);

        $response = $this->postJson('/chat', [
            'message' => 'Lebih bagus mana Danau Toba atau Berastagi untuk keluarga?',
        ])->assertOk()
            ->assertJsonPath('reply', 'Keduanya cocok untuk liburan keluarga.');

        $names = collect($response->json('destinations'))->pluck('name');
        $this->assertContains('Danau Toba', $names->all());
        $this->assertGreaterThanOrEqual(2, $names->count());
        Http::assertSent(fn (Request $request): bool => str_contains(
            $request->data()['systemInstruction']['parts'][0]['text'],
            '<ontology_context>',
        ) && str_contains($request->data()['systemInstruction']['parts'][0]['text'], 'Danau Toba')
            && str_contains($request->data()['systemInstruction']['parts'][0]['text'], 'Berastagi'));
    }

    public function test_dataset_statistics_are_answered_locally_from_the_ontology(): void
    {
        config()->set('services.gemini.key', 'test-key');
        $total = app(DestinationRepository::class)->all()->count();

        $response = $this->postJson('/chat', ['message' => 'Berapa total wisata?']);

        $response->assertOk()
            ->assertJsonStructure(['reply', 'destinations']);
        $this->assertStringContainsString('**'.$total.' destinasi**', $response->json('reply'));
        Http::assertNothingSent();
    }

    public function test_destination_details_do_not_include_weather_information(): void
    {
        config()->set('services.gemini.key', 'test-key');

        $response = $this->postJson('/chat', ['message' => 'Info Danau Toba']);

        $response->assertOk()
            ->assertJsonStructure(['reply', 'destinations'])
            ->assertJsonPath('destinations.0.name', 'Danau Toba');

        $this->assertStringNotContainsString('cuaca', mb_strtolower($response->json('reply')));
        Http::assertNothingSent();
    }

    public function test_greeting_is_answered_locally_without_a_gemini_key(): void
    {
        config()->set('services.gemini.key', '');

        $this->postJson('/chat', ['message' => 'Halo!'])
            ->assertOk()
            ->assertJsonStructure(['reply', 'destinations'])
            ->assertJsonPath('destinations', []);

        Http::assertNothingSent();
    }

    public function test_chatbot_is_rate_limited(): void
    {
        config()->set('services.gemini.key', '');
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55']);

        for ($request = 0; $request < 10; $request++) {
            $this->postJson('/chat', ['message' => 'Halo'])->assertOk();
        }

        $this->postJson('/chat', ['message' => 'Halo'])->assertStatus(429);
    }

    public function test_local_engine_understands_slang_recommendation_intent_typos_and_history_context(): void
    {
        config()->set('services.gemini.key', '');

        // 1. General recommendation intent with slang & number without specific region/type
        $general = $this->postJson('/chat', [
            'message' => 'spill 3 rekomendasi wisata dong cuy',
        ])->assertOk();
        $this->assertCount(3, $general->json('destinations'));
        $this->assertStringContainsString('**3', $general->json('reply'));

        // 2. Slang category & price ('healing' => Alam, 'murmer' => murah, 'yg' => yang)
        $slang = $this->postJson('/chat', [
            'message' => 'tempat healing yg murmer',
        ])->assertOk();
        $this->assertNotEmpty($slang->json('destinations'));

        // 3. Typo tolerance on region ('samosr' => Kabupaten Samosir) & destination ('danau tobaa' => Danau Toba)
        $regionTypo = $this->postJson('/chat', [
            'message' => 'rekomen wisata di samosr',
        ])->assertOk();
        $this->assertNotEmpty($regionTypo->json('destinations'));
        $this->assertStringContainsString('Samosir', $regionTypo->json('reply'));

        $destTypo = $this->postJson('/chat', [
            'message' => 'info danau tobaa',
        ])->assertOk();
        $this->assertSame('Danau Toba', $destTypo->json('destinations.0.name'));

        // 4. Context carry-over from $history ('yang gratis aja' inherits Kabupaten Karo + Alam)
        $followUp = $this->postJson('/chat', [
            'message' => 'yang gratis aja',
            'history' => [
                ['role' => 'user', 'text' => 'wisata alam di Kabupaten Karo'],
                ['role' => 'model', 'text' => 'Berikut rekomendasi wisata Alam di Kabupaten Karo...'],
            ],
        ])->assertOk();
        $this->assertNotEmpty($followUp->json('destinations'));
        $this->assertStringContainsString('Karo', $followUp->json('reply'));

        Http::assertNothingSent();
    }
}
