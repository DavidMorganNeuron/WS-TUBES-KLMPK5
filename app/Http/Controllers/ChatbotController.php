<?php

namespace App\Http\Controllers;

use App\Data\Destination;
use App\Services\Ontology\DestinationRepository;
use App\Services\Ontology\TypoCorrector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class ChatbotController extends Controller
{
    /**
     * Kata yang terlalu umum untuk dijadikan kata kunci pencarian konteks.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'yang', 'untuk', 'dengan', 'dari', 'saya', 'apa', 'dimana', 'bagaimana', 'rekomendasi',
        'wisata', 'tempat', 'destinasi', 'terbaik', 'dan', 'atau', 'juga', 'bisa', 'tolong',
        'the', 'and', 'for', 'with', 'what', 'where', 'how', 'recommend', 'recommendation',
        'please', 'about', 'near', 'best', 'place', 'places', 'travel', 'tourist', 'tourism',
        'bandingkan', 'perbandingan', 'membandingkan', 'lebih', 'bagus', 'mana', 'keluarga',
        'anak-anak', 'itinerary', 'itinerari', 'buatkan', 'perjalanan',
    ];

    /**
     * @var array<string, string>
     */
    private const TYPE_HINTS = [
        'alam' => 'Alam', 'nature' => 'Alam', 'gunung' => 'Alam', 'air terjun' => 'Alam',
        'danau' => 'Alam', 'hutan' => 'Alam', 'waterfall' => 'Alam', 'lake' => 'Alam',
        'budaya' => 'Budaya', 'culture' => 'Budaya', 'cultural' => 'Budaya', 'heritage' => 'Budaya',
        'museum' => 'Budaya', 'sejarah' => 'Budaya', 'history' => 'Budaya', 'adat' => 'Budaya',
        'religi' => 'Religi', 'religion' => 'Religi', 'religious' => 'Religi', 'masjid' => 'Religi',
        'gereja' => 'Religi', 'vihara' => 'Religi', 'klenteng' => 'Religi', 'church' => 'Religi',
        'bahari' => 'Bahari', 'marine' => 'Bahari', 'pantai' => 'Bahari', 'beach' => 'Bahari',
        'pulau' => 'Bahari', 'island' => 'Bahari', 'laut' => 'Bahari',
        'kuliner' => 'Kuliner', 'culinary' => 'Kuliner', 'food' => 'Kuliner',
        'buatan' => 'Buatan', 'man-made' => 'Buatan', 'theme park' => 'Buatan', 'waterpark' => 'Buatan',
        'hiburan' => 'Buatan',
    ];

    /**
     * @var list<string>
     */
    private const RECOMMENDATION_INTENT_HINTS = [
        'berikan', 'beri', 'rekomendasi', 'rekomendasikan', 'rekomen', 'rekomendasiin',
        'saran', 'saranin', 'kasih', 'kasi', 'kasih tau', 'spill', 'spil',
        'info', 'infokan', 'cariin', 'carikan', 'list', 'daftar',
        'mana aja', 'apa aja', 'mana saja', 'apa saja',
        'dong', 'cuy', 'min', 'guys', 'recommend', 'wisata terbaik',
    ];

    /**
     * @var array<string, string>
     */
    private const SLANG_PHRASE_MAP = [
        'kasih tau' => 'informasi',
        'mana aja' => 'mana saja',
        'apa aja' => 'apa saja',
    ];

    /**
     * @var array<string, string>
     */
    private const SLANG_WORD_MAP = [
        'yg' => 'yang',
        'gak' => 'tidak',
        'ga' => 'tidak',
        'nggak' => 'tidak',
        'ngga' => 'tidak',
        'dmn' => 'di mana',
        'dimana' => 'di mana',
        'brp' => 'berapa',
        'skrg' => 'sekarang',
        'bgt' => 'banget',
        'murmer' => 'murah',
        'healing' => 'alam',
        'foto2' => 'spot foto',
        'estetik' => 'spot foto',
        'instagramable' => 'spot foto',
    ];

    /**
     * @var list<string>
     */
    private const FOLLOW_UP_HINTS = [
        'yang gratis', 'yang murah', 'gratis aja', 'murah aja', 'kalau ', 'kalo ',
        'gimana ', 'bagaimana ', 'tambah ', 'lagi', 'selain itu', 'ada lagi',
        'di medan', 'di karo', 'di samosir', 'di toba', 'yg gratis', 'yg murah',
    ];

    /**
     * @var list<string>
     */
    private const COMPLEX_HINTS = [
        'itinerary', 'itinerari', 'rencana perjalanan', 'susun perjalanan', 'susun rute', 'rundown',
        'bandingkan', 'perbandingan', 'membandingkan', 'compare', 'comparison', 'lebih bagus mana',
        'kenapa', 'mengapa', 'bagaimana rute', 'tips', 'cocok untuk', 'keluarga', 'anak-anak',
        'honeymoon',
    ];

    /**
     * @var list<string>
     */
    private const FREE_HINTS = ['gratis', 'free', 'tanpa tiket'];

    /**
     * @var list<string>
     */
    private const CHEAP_HINTS = ['murah', 'termurah'];

    /**
     * @var list<string>
     */
    private const LOWEST_RATING_HINTS = [
        'rating terendah', 'rating paling rendah', 'rating kecil', 'rating terendah', 'paling rendah rating', 'terburuk', 'rating rendah',
    ];

    /**
     * @var list<string>
     */
    private const EXPENSIVE_HINTS = ['termahal', 'paling mahal', 'harga tertinggi'];

    /**
     * @var list<string>
     */
    private const TOP_HINTS = ['terbaik', 'terpopuler', 'populer', 'rating tertinggi', 'favorit'];

    /**
     * @var list<string>
     */
    private const SOCIAL_CONTACT_HINTS = ['instagram', 'ig', 'sosmed', 'media sosial', 'akun', 'kontak'];

    /**
     * @var list<string>
     */
    private const INSTAGRAM_HINTS = ['instagram', 'ig'];

    /**
     * @var array<string, list<string>>
     */
    private const ATTRIBUTE_HINTS = [
        'belajar' => ['belajar', 'mempelajari', 'edukasi'],
        'mempelajari' => ['belajar', 'mempelajari', 'edukasi'],
        'edukasi' => ['belajar', 'mempelajari', 'edukasi'],
        'belanja' => ['berbelanja', 'oleh-oleh', 'toko oleh-oleh', 'toko suvenir'],
        'berbelanja' => ['berbelanja', 'belanja', 'oleh-oleh', 'toko suvenir'],
        'oleh-oleh' => ['berbelanja', 'oleh-oleh', 'toko oleh-oleh', 'toko suvenir'],
        'doa' => ['berdoa', 'beribadah', 'ibadah'],
        'berdoa' => ['berdoa', 'beribadah', 'ibadah'],
        'ibadah' => ['berdoa', 'beribadah', 'ibadah'],
        'beribadah' => ['berdoa', 'beribadah', 'ibadah'],
        'ziarah' => ['berziarah', 'ziarah'],
        'berziarah' => ['berziarah', 'ziarah'],
        'renang' => ['berenang', 'renang', 'kolam renang'],
        'berenang' => ['berenang', 'renang', 'kolam renang'],
        'foto' => ['berfoto', 'foto', 'spot foto', 'photobooth'],
        'berfoto' => ['berfoto', 'foto', 'spot foto', 'photobooth'],
        'spot foto' => ['berfoto', 'foto', 'spot foto', 'photobooth'],
        'interaksi' => ['berinteraksi', 'interaksi'],
        'berinteraksi' => ['berinteraksi', 'interaksi'],
        'kemah' => ['berkemah', 'camping', 'kemah'],
        'berkemah' => ['berkemah', 'camping', 'kemah'],
        'camping' => ['berkemah', 'camping', 'kemah'],
        'layar' => ['berlayar', 'perahu', 'layar'],
        'berlayar' => ['berlayar', 'perahu', 'layar'],
        'perahu' => ['berlayar', 'perahu', 'layar'],
        'main' => ['bermain', 'wahana', 'taman bermain'],
        'bermain' => ['bermain', 'wahana', 'taman bermain'],
        'wahana' => ['bermain', 'wahana', 'taman bermain'],
        'santai' => ['bersantai', 'gazebo', 'area istirahat'],
        'bersantai' => ['bersantai', 'gazebo', 'area istirahat'],
        'gazebo' => ['bersantai', 'gazebo'],
        'selancar' => ['berselancar', 'surfing', 'selancar'],
        'berselancar' => ['berselancar', 'surfing', 'selancar'],
        'surfing' => ['berselancar', 'surfing', 'selancar'],
        'pancing' => ['memancing', 'pancing'],
        'memancing' => ['memancing', 'pancing'],
        'daki' => ['mendaki', 'trekking', 'daki'],
        'mendaki' => ['mendaki', 'trekking', 'daki'],
        'trekking' => ['mendaki', 'trekking', 'daki'],
        'telusur' => ['menelusuri', 'menyusuri', 'menjelajahi', 'menjelajah'],
        'menelusuri' => ['menelusuri', 'menyusuri', 'menjelajahi', 'menjelajah'],
        'menyusuri' => ['menelusuri', 'menyusuri', 'menjelajahi', 'menjelajah'],
        'jelajah' => ['menelusuri', 'menyusuri', 'menjelajahi', 'menjelajah'],
        'menjelajahi' => ['menelusuri', 'menyusuri', 'menjelajahi', 'menjelajah'],
        'amati' => ['mengamati', 'amati'],
        'mengamati' => ['mengamati', 'amati'],
        'apresiasi' => ['mengapresiasi', 'apresiasi'],
        'mengapresiasi' => ['mengapresiasi', 'apresiasi'],
        'makan' => ['menikmati makanan', 'makanan', 'makan', 'jajan', 'rumah makan', 'warung makan'],
        'makanan' => ['menikmati makanan', 'makanan', 'makan', 'jajan', 'rumah makan', 'warung makan'],
        'jajan' => ['menikmati makanan', 'makanan', 'makan', 'jajan', 'rumah makan', 'warung makan'],
        'menikmati makanan' => ['menikmati makanan', 'makanan', 'makan', 'jajan', 'rumah makan', 'warung makan'],
        'pemandangan' => ['menikmati pemandangan', 'panorama', 'pemandangan'],
        'panorama' => ['menikmati pemandangan', 'panorama', 'pemandangan'],
        'menikmati pemandangan' => ['menikmati pemandangan', 'panorama', 'pemandangan'],
        'tonton' => ['menonton', 'tonton'],
        'menonton' => ['menonton', 'tonton'],
        'selam' => ['menyelam', 'snorkeling', 'diving', 'selam'],
        'menyelam' => ['menyelam', 'snorkeling', 'diving', 'selam'],
        'snorkeling' => ['menyelam', 'snorkeling', 'diving', 'selam'],
        'diving' => ['menyelam', 'snorkeling', 'diving', 'selam'],
        'kursi roda' => ['akses kursi roda', 'kursi roda'],
        'akses kursi roda' => ['akses kursi roda', 'kursi roda'],
        'musholla' => ['musala', 'musholla', 'mushola'],
        'mushola' => ['musala', 'musholla', 'mushola'],
        'musala' => ['musala', 'musholla', 'mushola'],
        'pemandu' => ['pemandu wisata', 'pemandu'],
        'pemandu wisata' => ['pemandu wisata', 'pemandu'],
        'hotel' => ['penginapan', 'hotel', 'resort'],
        'resort' => ['penginapan', 'hotel', 'resort'],
        'penginapan' => ['penginapan', 'hotel', 'resort'],
        'parkir' => ['tempat parkir', 'parkir'],
        'tempat parkir' => ['tempat parkir', 'parkir'],
        'toilet' => ['toilet', 'wc'],
        'wc' => ['toilet', 'wc'],
        'wifi' => ['wi-fi', 'wifi'],
        'wi-fi' => ['wi-fi', 'wifi'],
        'sewa peralatan' => ['sewa peralatan', 'sewa alat'],
        'sewa alat' => ['sewa peralatan', 'sewa alat'],
        'kolam renang' => ['kolam renang', 'berenang', 'renang'],
        'rumah makan' => ['rumah makan', 'warung makan', 'menikmati makanan'],
        'warung makan' => ['rumah makan', 'warung makan', 'menikmati makanan'],
        'taman bermain' => ['taman bermain', 'bermain', 'wahana'],
        'area istirahat' => ['area istirahat', 'bersantai'],
        'photobooth' => ['photobooth', 'spot foto', 'berfoto'],
        'mesin arkade' => ['mesin arkade', 'arkade'],
        'arkade' => ['mesin arkade', 'arkade'],
        'ruang diskusi' => ['ruang diskusi'],
        'toko suvenir' => ['toko suvenir', 'oleh-oleh'],
    ];

    public function chat(Request $request, DestinationRepository $repository): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:800'],
            'history' => ['sometimes', 'array', 'max:20'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,model'],
            'history.*.text' => ['required_with:history', 'string', 'max:4000'],
        ]);

        try {
            return $this->processChat($validated, $repository);
        } catch (Throwable $exception) {
            Log::error('Kegagalan tak terduga pada endpoint chatbot; balasan aman digunakan.', [
                'exception' => $exception::class,
            ]);

            return $this->safeJsonResponse($this->lastResortReply());
        }
    }

    /**
     * @param  array{message: string, history?: list<array{role: string, text: string}>}  $validated
     */
    private function processChat(array $validated, DestinationRepository $repository): JsonResponse
    {
        $message = trim($validated['message']);
        $history = $validated['history'] ?? [];
        try {
            $normalizedQuery = $this->normalizeUserQuery($message);
            $localReply = $this->tryLocalReply($message, $repository, $normalizedQuery);
            if ($this->isValidResponsePayload($localReply)) {
                return $this->safeJsonResponse($localReply);
            }
        } catch (Throwable $exception) {
            Log::warning('Balasan chatbot lokal gagal; lapisan berikutnya akan dicoba.', [
                'exception' => $exception::class,
            ]);
        }

        try {
            $normalizedQuery = $this->normalizeUserQuery($message, $repository);
            $localReply = $this->tryLocalReply($message, $repository, $normalizedQuery);
            if ($this->isValidResponsePayload($localReply)) {
                return $this->safeJsonResponse($localReply);
            }
        } catch (Throwable $exception) {
            Log::warning('Normalisasi pertanyaan chatbot gagal; pencarian dilanjutkan tanpa koreksi ontologi.', [
                'exception' => $exception::class,
            ]);

            try {
                $normalizedQuery = $this->normalizeUserQuery($message);
            } catch (Throwable $fallbackException) {
                Log::error('Normalisasi cadangan chatbot gagal.', [
                    'exception' => $fallbackException::class,
                ]);

                return $this->safeJsonResponse($this->lastResortReply());
            }
        }

        $apiKey = trim((string) config('services.gemini.key', ''));
        $intent = null;
        $candidates = null;
        if ($apiKey !== '') {
            try {
                $intent = $this->buildSearchIntent(
                    $normalizedQuery['corrected'],
                    $history,
                    $repository,
                    $normalizedQuery['clean'],
                );
                $candidates = $this->matchingDestinations($normalizedQuery['corrected'], $intent, $repository);
                if ($candidates->isNotEmpty()) {
                    $geminiReply = $this->tryGeminiReply(
                        $message,
                        $history,
                        $normalizedQuery,
                        $intent,
                        $candidates,
                        $repository,
                        $apiKey,
                    );

                    if ($this->isValidResponsePayload($geminiReply)) {
                        return $this->safeJsonResponse($geminiReply);
                    }
                }
            } catch (Throwable $exception) {
                Log::warning('Penyusunan konteks chatbot gagal; pencarian lokal akan dicoba.', [
                    'exception' => $exception::class,
                ]);
            }
        } elseif ($apiKey === '') {
            Log::warning('GEMINI_API_KEY belum dikonfigurasi; chatbot menggunakan pencarian lokal.');
        }

        try {
            $fallbackReply = $this->fallbackSearchReply(
                $normalizedQuery['corrected'],
                $repository,
                $history,
                $intent,
                $candidates,
                $normalizedQuery['clean'],
            );
            if ($this->isValidResponsePayload($fallbackReply)) {
                return $this->safeJsonResponse($fallbackReply);
            }
        } catch (Throwable $exception) {
            Log::error('Pencarian cadangan chatbot gagal; balasan aman akan digunakan.', [
                'exception' => $exception::class,
            ]);
        }

        return $this->safeJsonResponse($this->lastResortReply());
    }

    /**
     * @param  list<array{role: string, text: string}>  $history
     * @param  array{clean: string, corrected: string, isRecommendationIntent: bool}  $normalizedQuery
     * @param  array<string, mixed>  $intent
     * @param  Collection<int, Destination>  $candidates
     * @return array{reply: string, destinations: list<array{id: string, name: string, latitude: float, longitude: float, location: string}>}|null
     */
    private function tryGeminiReply(
        string $message,
        array $history,
        array $normalizedQuery,
        array $intent,
        Collection $candidates,
        DestinationRepository $repository,
        string $apiKey,
    ): ?array {
        try {
            $cacheKey = $this->geminiCacheKey($normalizedQuery['corrected'], $history, $intent);

            try {
                $cachedReply = Cache::get($cacheKey);
                if ($this->isValidResponsePayload($cachedReply)) {
                    return $cachedReply;
                }
            } catch (Throwable $exception) {
                Log::warning('Cache balasan Gemini tidak tersedia; permintaan diteruskan ke Gemini.', [
                    'exception' => $exception::class,
                ]);
            }

            $response = Http::acceptJson()
                ->timeout(20)
                ->connectTimeout(5)
                ->retry(
                    2,
                    static fn (int $attempt): int => 500 * (2 ** max(0, $attempt - 1)),
                    static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                        || ($exception instanceof RequestException
                            && in_array($exception->response->status(), [429, 503], true)),
                    false,
                )
                ->withHeaders(['x-goog-api-key' => $apiKey])
                ->post($this->endpoint(), [
                    'systemInstruction' => [
                        'parts' => [
                            ['text' => $this->systemInstruction(
                                $normalizedQuery['corrected'],
                                $intent,
                                $candidates,
                                $repository,
                            )],
                        ],
                    ],
                    'contents' => $this->contents($message, $history),
                    'generationConfig' => [
                        'maxOutputTokens' => 900,
                        'temperature' => 0.7,
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Gemini mengembalikan respons gagal; chatbot menggunakan pencarian lokal.', [
                    'status' => $response->status(),
                ]);

                return null;
            }

            $reply = collect($response->json('candidates.0.content.parts', []))
                ->pluck('text')
                ->filter(fn (mixed $text): bool => is_string($text) && trim($text) !== '')
                ->implode("\n");

            if (trim($reply) === '' || ! mb_check_encoding($reply, 'UTF-8')) {
                return null;
            }

            if ($intent['isRecommendation']
                && ! $this->replyNamesRequestedCandidates($reply, $candidates, $intent['limit'])) {
                Log::warning('Balasan Gemini tidak menyebut jumlah kandidat yang diminta; pencarian lokal digunakan.');

                return null;
            }

            $payload = $this->responsePayload(trim($reply), $candidates, $intent['limit']);

            if (! $this->isValidResponsePayload($payload)) {
                return null;
            }

            try {
                Cache::put($cacheKey, $payload, now()->addMinutes(30));
            } catch (Throwable $exception) {
                Log::warning('Balasan Gemini valid tidak dapat disimpan ke cache.', [
                    'exception' => $exception::class,
                ]);
            }

            return $payload;
        } catch (Throwable $exception) {
            Log::warning('Pemanggilan Gemini gagal; chatbot menggunakan pencarian lokal.', [
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    /**
     * @param  list<array{role: string, text: string}>  $history
     */
    private function geminiCacheKey(string $normalizedQuestion, array $history, array $intent): string
    {
        $question = Str::lower(trim(preg_replace('/\s+/u', ' ', $normalizedQuestion) ?? $normalizedQuestion));
        $filters = [
            'region' => $intent['region'],
            'types' => $intent['types'],
            'attributes' => $intent['attributeTerms'],
            'sort' => $intent['sortMode'],
            'limit' => $intent['limit'],
            'free_only' => $intent['wantsFree'],
        ];
        $dimensions = [
            'question' => $question,
            'filters' => $filters,
            'history' => array_slice($history, -6),
            'model' => (string) config('services.gemini.model', 'gemini-3.5-flash-lite'),
        ];
        $serialized = json_encode($dimensions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return 'chat_ai:'.md5($serialized === false ? serialize($dimensions) : $serialized);
    }

    /**
     * @phpstan-assert-if-true array{reply: non-empty-string, destinations: array} $payload
     */
    private function isValidResponsePayload(mixed $payload): bool
    {
        if (! is_array($payload)
            || ! is_string($payload['reply'] ?? null)
            || trim($payload['reply']) === ''
            || ! mb_check_encoding($payload['reply'], 'UTF-8')
            || ! is_array($payload['destinations'] ?? null)) {
            return false;
        }

        foreach ($payload['destinations'] as $destination) {
            if (! is_array($destination)
                || ! is_string($destination['id'] ?? null)
                || ! is_string($destination['name'] ?? null)
                || ! is_numeric($destination['latitude'] ?? null)
                || ! is_numeric($destination['longitude'] ?? null)
                || ! is_string($destination['location'] ?? null)
                || ! mb_check_encoding($destination['id'], 'UTF-8')
                || ! mb_check_encoding($destination['name'], 'UTF-8')
                || ! mb_check_encoding($destination['location'], 'UTF-8')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{reply: string, destinations: array}  $payload
     */
    private function safeJsonResponse(array $payload): JsonResponse
    {
        try {
            return response()->json($payload);
        } catch (Throwable $exception) {
            Log::error('Payload chatbot tidak dapat diserialisasi; balasan aman digunakan.', [
                'exception' => $exception::class,
            ]);

            return response()->json($this->lastResortReply());
        }
    }

    /**
     * @return array{reply: string, destinations: array{}}
     */
    private function lastResortReply(): array
    {
        return [
            'reply' => 'Maaf, layanan WayFinder AI sedang mengalami gangguan. Silakan coba lagi nanti, atau buka [halaman destinasi](/destinations) maupun [peta](/map).',
            'destinations' => [],
        ];
    }

    /**
     * @param  array{clean: string, corrected: string, isRecommendationIntent: bool}|null  $normalizedQuery
     * @return array{reply: string, destinations: list<array{id: string, name: string, latitude: float, longitude: float, location: string}>}|null
     */
    private function tryLocalReply(
        string $message,
        DestinationRepository $repository,
        ?array $normalizedQuery = null,
    ): ?array {
        $normalizedQuery ??= $this->normalizeUserQuery($message, $repository);
        $clean = $normalizedQuery['clean'];
        $corrected = $normalizedQuery['corrected'];

        if ($clean === '') {
            return null;
        }

        if ($this->isGreeting($clean)) {
            return $this->responsePayload(
                'Halo! Saya WayFinder AI, asisten wisata Sumatera Utara. Saya bisa membantu mencari destinasi berdasarkan kategori, wilayah, harga, rating, dan informasi destinasi. Contoh: "wisata alam di Kabupaten Karo", "tempat wisata gratis", atau "info Istana Maimun".',
            );
        }

        if ($this->hasAny($corrected, ['berapa total', 'jumlah wisata', 'ada berapa', 'statistik'])) {
            return $this->statisticsReply($repository);
        }

        if ($this->isDestinationDetailIntent($corrected)) {
            $mentioned = $this->findMentionedDestinations($corrected, $repository->all());

            if ($mentioned->count() === 1) {
                $destination = $mentioned->first();

                if ($this->isSocialContactIntent($corrected)) {
                    return $this->contactInformationReply($destination, $corrected);
                }

                return $this->destinationDetailReply($destination, $repository);
            }
        }

        return null;
    }

    /**
     * @return array{reply: string, destinations: list<array{id: string, name: string, latitude: float, longitude: float, location: string}>}
     */
    private function statisticsReply(DestinationRepository $repository): array
    {
        $destinations = $repository->all();
        $facets = $repository->facets();
        $types = collect($facets['types'] ?? [])
            ->map(fn (int $count, string $type): string => '- '.$type.': '.$count)
            ->implode("\n");

        $reply = sprintf(
            "Dataset WayFinder mencatat **%d destinasi** di **%d kabupaten/kota**. Ada %d destinasi dengan rating, dengan rata-rata rating %s.\n\nJumlah destinasi per kategori:\n%s",
            $destinations->count(),
            count($facets['kabupaten'] ?? []),
            (int) ($facets['rated_count'] ?? 0),
            isset($facets['average_rating']) && $facets['average_rating'] !== null
                ? number_format((float) $facets['average_rating'], 2, ',', '.')
                : 'belum tersedia',
            $types !== '' ? $types : '- Data kategori belum tersedia.',
        );

        return $this->responsePayload($reply, $repository->topRated(5));
    }

    /**
     * @return array{reply: string, destinations: list<array{id: string, name: string, latitude: float, longitude: float, location: string}>}
     */
    private function contactInformationReply(Destination $destination, string $message): array
    {
        if ($this->hasWordOrPhrase($message, self::INSTAGRAM_HINTS)) {
            $instagram = trim($destination->instagram);
            $reply = $instagram !== ''
                ? "**{$destination->name}**\n\nInstagram: {$instagram}"
                : "**{$destination->name}** belum mencantumkan akun Instagram di dataset WayFinder.";

            return $this->responsePayload($reply, collect([$destination]));
        }

        $contactDetails = [];
        foreach ([
            'Telepon' => $destination->phone,
            'Email/kontak resmi' => $destination->email,
            'Instagram' => $destination->instagram,
            'Situs web' => $destination->website,
        ] as $label => $value) {
            $value = trim($value);
            if ($value !== '') {
                $contactDetails[] = '- '.$label.': '.$value;
            }
        }

        if ($contactDetails === [] && trim($destination->contact) !== '') {
            $contactDetails[] = '- Kontak: '.trim($destination->contact);
        }

        $reply = $contactDetails !== []
            ? "**{$destination->name}**\n\nInformasi kontak yang tercatat:\n".implode("\n", $contactDetails)
            : "Informasi kontak untuk **{$destination->name}** belum tercatat di dataset WayFinder.";

        return $this->responsePayload($reply, collect([$destination]));
    }

    /**
     * @param  array<string, mixed>  $intent
     * @param  Collection<int, Destination>  $destinations
     * @return array{reply: string, destinations: list<array{id: string, name: string, latitude: float, longitude: float, location: string}>}
     */
    private function recommendationReply(array $intent, Collection $destinations, bool $isFallback = false): array
    {
        $destinations = $destinations->take($intent['limit'])->values();

        if ($destinations->isEmpty()) {
            return $this->responsePayload(
                'Belum ada destinasi yang cocok dengan filter tersebut pada dataset WayFinder. Coba kategori atau wilayah lain.',
            );
        }

        $count = $destinations->count();
        $category = $intent['types'][0] ?? '';
        $subject = $category !== '' ? 'wisata '.$category : 'destinasi wisata';
        $location = $intent['region'] !== null ? ' di '.$intent['region'] : ' di Sumatera Utara';
        $attributeReason = $intent['attributeTerms'] !== []
            ? ' yang relevan dengan '.implode(', ', $intent['attributeTerms'])
            : '';

        $openings = match ($intent['sortMode']) {
            'rating_asc' => [
                "Berikut **{$count} {$subject}{$location} dengan rating terendah** yang tercatat:",
                "Kalau ingin melihat rating terendah, ini **{$count} {$subject}{$location}** dari dataset:",
            ],
            'rating_desc' => [
                "Berikut **{$count} {$subject}{$location} dengan rating tertinggi** di dataset:",
                "Untuk rating tertinggi, ini **{$count} {$subject}{$location}** yang bisa dipertimbangkan:",
            ],
            'price_asc' => [
                "Ini **{$count} {$subject}{$location} dengan harga termurah** yang tercatat:",
                "Kalau mencari tiket yang lebih terjangkau, berikut **{$count} {$subject}{$location}**:",
            ],
            'price_desc' => [
                "Berikut **{$count} {$subject}{$location} dengan harga termahal** di dataset:",
                "Untuk pilihan dengan tiket paling mahal, ini **{$count} {$subject}{$location}** yang tercatat:",
            ],
            default => $intent['wantsFree']
                ? [
                    "Berikut **{$count} {$subject}{$location} yang gratis tiket masuk**:",
                    "Saya menemukan **{$count} {$subject}{$location} tanpa tiket masuk**:",
                ]
                : [
                    "Saya menemukan **{$count} {$subject}{$location}** yang sesuai.",
                    "Boleh, ini **{$count} {$subject}{$location}** yang cocok dengan pencarianmu.",
                    "Dari dataset WayFinder, berikut **{$count} {$subject}{$location}** yang layak dilihat.",
                ],
        };
        $opening = rtrim($openings[array_rand($openings)], ': .').$attributeReason.':';

        if ($intent['isFollowUp']) {
            $opening = 'Melanjutkan pencarian sebelumnya, '.$opening;
        }

        $prefix = $isFallback
            ? "WayFinder AI sedang tidak tersedia, jadi saya mengambil pilihan ini dari dataset lokal.\n\n"
            : '';
        $reply = $prefix.$opening."\n\n".$this->recommendationList($destinations);

        return $this->responsePayload($reply, $destinations, $intent['limit']);
    }

    /**
     * @param  list<array{role: string, text: string}>  $history
     * @return array{
     *     region: ?string,
     *     types: list<string>,
     *     attributeTerms: list<string>,
     *     sortMode: string,
     *     limit: int,
     *     requestedLimit: int,
     *     wantsFree: bool,
     *     wantsCheap: bool,
     *     isRecommendation: bool,
     *     isFollowUp: bool
     * }
     */
    private function buildSearchIntent(
        string $message,
        array $history,
        DestinationRepository $repository,
        ?string $rawMessage = null,
    ): array {
        $mentioned = $this->findMentionedDestinations($message, $repository->all());
        $region = $this->extractRegion($message, $repository);
        $regionName = $region !== null
            ? Str::lower(preg_replace('/^(?:kabupaten|kota)\s+/iu', '', $region) ?? $region)
            : null;
        $hasExplicitRegion = preg_match('/\b(?:kabupaten|kota)\b/u', $message) === 1
            || ($regionName !== null
                && preg_match('/\bdi\s+'.preg_quote($regionName, '/').'(?!\p{L})/iu', $message) === 1);
        if ($this->isComplexNarrativeQuery($message) && $mentioned->count() > 1 && ! $hasExplicitRegion) {
            $region = null;
        }

        $types = $this->extractTypes($message);
        $attributeTerms = $this->extractAttributeTerms($message, $repository);
        if (in_array('Kuliner', $types, true)) {
            $attributeTerms = array_values(array_diff($attributeTerms, ['kuliner']));
        }

        $wantsFree = $this->hasAny($message, self::FREE_HINTS);
        $wantsCheap = $this->hasAny($message, self::CHEAP_HINTS);
        $limitQuery = $rawMessage ?? $message;
        $requestedLimit = $this->extractRequestedLimit($limitQuery, 0);
        $limit = $this->extractRequestedLimit($limitQuery);
        $isFollowUp = false;

        if ($history !== [] && ($region === null || $types === [])) {
            $historyContext = $this->extractHistoryContext($history, $repository);
            if ($region === null && $historyContext['region'] !== null) {
                $region = $historyContext['region'];
                $isFollowUp = true;
            }
            if ($types === [] && $historyContext['types'] !== []) {
                $types = $historyContext['types'];
                $isFollowUp = true;
            }
        }

        $sortMode = match (true) {
            $this->hasAny($message, self::LOWEST_RATING_HINTS) => 'rating_asc',
            $this->hasAny($message, self::EXPENSIVE_HINTS) => 'price_desc',
            $wantsCheap => 'price_asc',
            $this->hasAny($message, self::TOP_HINTS) => 'rating_desc',
            default => 'default',
        };
        $isRecommendation = $this->hasAny($message, self::RECOMMENDATION_INTENT_HINTS)
            || $attributeTerms !== []
            || $wantsFree
            || $wantsCheap
            || $sortMode !== 'default'
            || $requestedLimit > 0
            || (($types !== [] || $region !== null) && ! $this->isComplexNarrativeQuery($message));

        return [
            'region' => $region,
            'types' => $types,
            'attributeTerms' => $attributeTerms,
            'sortMode' => $sortMode,
            'limit' => $limit,
            'requestedLimit' => $requestedLimit,
            'wantsFree' => $wantsFree,
            'wantsCheap' => $wantsCheap,
            'isRecommendation' => $isRecommendation,
            'isFollowUp' => $isFollowUp,
        ];
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return Collection<int, Destination>
     */
    private function matchingDestinations(string $message, array $intent, DestinationRepository $repository): Collection
    {
        $allDestinations = $repository->all();
        $mentioned = $this->findMentionedDestinations($message, $allDestinations);
        $mentionedTokens = $mentioned
            ->flatMap(fn (Destination $destination): array => preg_split(
                '/[^\p{L}\p{N}]+/u',
                Str::lower($destination->name.' '.$destination->alternativeName),
                -1,
                PREG_SPLIT_NO_EMPTY,
            ) ?: [])
            ->all();
        $queryTokens = collect(preg_split('/[^\p{L}\p{N}]+/u', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->filter(fn (string $token): bool => mb_strlen($token) >= 5)
            ->reject(fn (string $token): bool => in_array($token, self::STOPWORDS, true)
                || in_array($token, $mentionedTokens, true))
            ->unique()
            ->take(4);
        $keywordMatches = collect();
        foreach ($queryTokens as $token) {
            $keywordMatches = $keywordMatches->merge(
                $repository->search(['q' => $token, 'sort' => 'relevansi'])->take(3),
            );
        }

        $searchFilters = ['kabupaten' => $intent['region'], 'sort' => 'rating'];
        $searched = collect();

        if ($intent['types'] === []) {
            $searched = $repository->search($searchFilters);
        } else {
            foreach ($intent['types'] as $type) {
                $searched = $searched->merge($repository->search([
                    ...$searchFilters,
                    'jenis' => $type,
                ]));
            }
        }

        $destinations = $mentioned->merge($keywordMatches)->merge($searched)->unique('id')->values();

        if (! $intent['isRecommendation'] && ($mentioned->isNotEmpty() || $keywordMatches->isNotEmpty())) {
            $destinations = $mentioned->merge($keywordMatches)->unique('id')->values();
        } elseif ($intent['types'] !== []) {
            $types = $intent['types'];
            $destinations = $destinations->filter(
                fn (Destination $destination): bool => array_intersect($types, $destination->types) !== [],
            );
        }

        if ($intent['region'] !== null && ($intent['isRecommendation'] || $mentioned->isEmpty())) {
            $region = Str::lower(preg_replace('/^(?:kabupaten|kota)\s+/iu', '', $intent['region']) ?? $intent['region']);
            $destinations = $destinations->filter(
                fn (Destination $destination): bool => str_contains(Str::lower($destination->location), $region),
            );
        }

        if ($intent['attributeTerms'] !== []) {
            $terms = $intent['attributeTerms'];
            $destinations = $destinations->filter(
                fn (Destination $destination): bool => $this->matchesAttributeTerms($destination, $terms),
            );
        }

        if ($intent['wantsFree']) {
            $destinations = $destinations->filter(fn (Destination $destination): bool => $this->isFree($destination));
        }

        $destinations = $destinations->filter(fn (Destination $destination): bool => $this->hasValidCoordinates($destination));

        if (in_array($intent['sortMode'], ['rating_asc', 'rating_desc'], true)) {
            $destinations = $destinations->filter(fn (Destination $destination): bool => $destination->rating !== null);
        }

        if ($intent['sortMode'] === 'price_desc') {
            $destinations = $destinations->filter(
                fn (Destination $destination): bool => ($destination->lowestPrice() ?? 0.0) > 0.0,
            );
        }

        $destinations = match ($intent['sortMode']) {
            'rating_asc' => $destinations->sortBy(fn (Destination $destination): float => (float) $destination->rating),
            'rating_desc' => $destinations->sortByDesc(fn (Destination $destination): float => (float) $destination->rating),
            'price_asc' => $destinations->sortBy(fn (Destination $destination): array => [
                $destination->lowestPrice() ?? PHP_FLOAT_MAX,
                -($destination->rating ?? -1.0),
            ]),
            'price_desc' => $destinations->sortByDesc(fn (Destination $destination): float => $destination->lowestPrice() ?? 0.0),
            default => $destinations->sortByDesc(fn (Destination $destination): float => $destination->rating ?? -1.0),
        };

        if ($intent['sortMode'] === 'default' && ! $intent['isRecommendation'] && $mentioned->isNotEmpty()) {
            return $destinations->take($intent['limit'])->values();
        }

        if ($intent['sortMode'] === 'default') {
            $highlyRated = $destinations->filter(
                fn (Destination $destination): bool => $destination->rating !== null && $destination->rating >= 4.0,
            );
            if ($highlyRated->isNotEmpty()) {
                $destinations = $highlyRated;
            }

            $poolSize = max($intent['limit'], min(15, $intent['limit'] * 2));
            $destinations = $destinations->take($poolSize)->shuffle();
            if ($mentioned->isNotEmpty()) {
                $mentionedIds = $mentioned->pluck('id')->all();
                $pinned = $destinations->filter(
                    fn (Destination $destination): bool => in_array($destination->id, $mentionedIds, true),
                );
                $destinations = $pinned->merge(
                    $destinations->reject(
                        fn (Destination $destination): bool => in_array($destination->id, $mentionedIds, true),
                    ),
                );
            }
        }

        return $destinations->take($intent['limit'])->values();
    }

    /**
     * @param  Collection<int, Destination>  $candidates
     */
    private function replyNamesRequestedCandidates(string $reply, Collection $candidates, int $limit): bool
    {
        $lowerReply = Str::lower($reply);
        $mentionedCount = $candidates->filter(
            fn (Destination $destination): bool => str_contains($lowerReply, Str::lower($destination->name)),
        )->count();

        return $mentionedCount === min($limit, $candidates->count());
    }

    /**
     * @return array{reply: string, destinations: list<array{id: string, name: string, latitude: float, longitude: float, location: string}>}
     */
    private function destinationDetailReply(Destination $destination, DestinationRepository $repository): array
    {
        $types = $destination->types ?? [];
        $prices = $destination->priceLabels();
        $facilities = $destination->facilities ?? [];
        $activities = $destination->activities ?? [];
        $alternativeName = trim($destination->alternativeName) !== '' ? $destination->alternativeName : $unknown;
        $keywords = $destination->keywords !== [] ? implode(', ', $destination->keywords) : $unknown;
        $directions = trim($destination->directions) !== '' ? $destination->directions : $unknown;
        $media = trim($destination->media) !== '' ? $destination->media : $unknown;
        $instagram = trim($destination->instagram) !== '' ? $destination->instagram : $unknown;
        $contact = trim($destination->contact) !== '' ? $destination->contact : $unknown;
        $website = trim($destination->website) !== '' ? $destination->website : $unknown;
        $nearby = $destination->hasCoordinates()
            ? $repository->nearby($destination, 3)
                ->map(fn (Destination $nearbyDestination): string => $nearbyDestination->name ?? '')
                ->filter()
                ->implode(', ')
            : '';
        $unknown = (string) config('ontology.unknown_label', 'tidak tercatat');

        $reply = sprintf(
            "**%s**\n\n%s\n\n- Nama alternatif: %s\n- Kategori: %s\n- Wilayah: %s\n- Rating: %s\n- Harga tiket: %s\n- Alamat: %s\n- Petunjuk lokasi: %s\n- Jadwal: %s\n- Fasilitas: %s\n- Aktivitas: %s\n- Kata kunci: %s\n- Instagram: %s\n- Kontak: %s\n- Situs web: %s\n- Media: %s\n- Destinasi terdekat: %s",
            $destination->name ?? 'Destinasi',
            ($destination->fullDescription ?? '') !== ''
                ? $destination->fullDescription
                : (($destination->shortDescription ?? '') !== '' ? $destination->shortDescription : $unknown),
            $alternativeName,
            $types !== [] ? implode(', ', $types) : $unknown,
            ($destination->location ?? '') !== '' ? $destination->location : $destination->province(),
            $destination->ratingLabel(),
            $prices !== [] ? implode(', ', $prices) : $unknown,
            ($destination->address ?? '') !== '' ? $destination->address : $unknown,
            $directions,
            $destination->openingHoursLabel(),
            $facilities !== [] ? implode(', ', $facilities) : $unknown,
            $activities !== [] ? implode(', ', $activities) : $unknown,
            $keywords,
            $instagram,
            $contact,
            $website,
            $media,
            $nearby !== '' ? $nearby : $unknown,
        );

        if ($destination->hasCoordinates()) {
            $reply .= "\n\n[Lihat lokasi di Google Maps](".$destination->mapsUrl().')';
        }

        return $this->responsePayload($reply, collect([$destination]));
    }

    /**
     * @return array{reply: string, destinations: list<array{id: string, name: string, latitude: float, longitude: float, location: string}>}
     */
    private function fallbackSearchReply(
        string $message,
        DestinationRepository $repository,
        array $history = [],
        ?array $intent = null,
        ?Collection $candidates = null,
        ?string $rawMessage = null,
    ): array {
        $intent ??= $this->buildSearchIntent(Str::lower($message), $history, $repository, $rawMessage);
        $candidates ??= $this->matchingDestinations(Str::lower($message), $intent, $repository);

        if ($candidates->isEmpty()) {
            return $this->responsePayload(
                'Belum ada destinasi yang cocok dengan pertanyaan tersebut di dataset lokal. Coba tanyakan kategori, wilayah, aktivitas, atau harga tiket yang lain.',
            );
        }

        return $this->recommendationReply($intent, $candidates, true);
    }

    /**
     * @param  Collection<int, Destination>  $destinations
     * @return list<array{id: string, name: string, latitude: float, longitude: float, location: string}>
     */
    private function mapDestinations(Collection $destinations, int $limit = 5): array
    {
        return $destinations
            ->filter(fn (mixed $destination): bool => $destination instanceof Destination
                && $this->hasValidCoordinates($destination))
            ->map(fn (Destination $destination): array => [
                'id' => $destination->id ?? '',
                'name' => $destination->name ?? '',
                'latitude' => (float) ($destination->latitude ?? 0.0),
                'longitude' => (float) ($destination->longitude ?? 0.0),
                'location' => $destination->location ?? '',
            ])
            ->take(min(10, max(1, $limit)))
            ->values()
            ->all();
    }

    private function hasValidCoordinates(Destination $destination): bool
    {
        $latitude = $destination->latitude ?? null;
        $longitude = $destination->longitude ?? null;

        return is_numeric($latitude)
            && is_numeric($longitude)
            && $latitude >= -90
            && $latitude <= 90
            && $longitude >= -180
            && $longitude <= 180;
    }

    private function responsePayload(string $reply, ?Collection $destinations = null, int $limit = 5): array
    {
        return [
            'reply' => $reply,
            'destinations' => $this->mapDestinations($destinations ?? collect(), $limit),
        ];
    }

    /**
     * @param  Collection<int, Destination>  $destinations
     */
    private function recommendationList(Collection $destinations): string
    {
        return $destinations
            ->map(fn (Destination $destination): string => sprintf(
                '- **%s** (%s) | Rating: %s | Tiket: %s | Aktivitas: %s | Fasilitas: %s',
                $destination->name ?? 'Destinasi',
                ($destination->location ?? '') !== '' ? $destination->location : $destination->province(),
                $destination->ratingLabel(),
                $destination->priceLabel(),
                ($destination->activities ?? []) !== [] ? implode(', ', $destination->activities) : 'tidak tercatat',
                ($destination->facilities ?? []) !== [] ? implode(', ', $destination->facilities) : 'tidak tercatat',
            ))
            ->implode("\n");
    }

    /**
     * Normalisasi bahasa gaul, singkatan chat, dan koreksi typo ringan pada pesan pengguna.
     *
     * @return array{clean: string, corrected: string, isRecommendationIntent: bool}
     */
    private function normalizeUserQuery(string $rawMessage, ?DestinationRepository $repository = null): array
    {
        $lower = mb_strtolower(trim($rawMessage), 'UTF-8');
        $clean = trim(preg_replace('/\s+/u', ' ', $lower) ?? $lower);

        if ($clean === '') {
            return [
                'clean' => '',
                'corrected' => '',
                'isRecommendationIntent' => false,
            ];
        }

        $isRecommendationIntent = $this->hasWordOrPhrase($clean, self::RECOMMENDATION_INTENT_HINTS);

        $normalized = $clean;
        foreach (self::SLANG_PHRASE_MAP as $phrase => $replacement) {
            $normalized = preg_replace('/(?<!\p{L})'.preg_quote($phrase, '/').'(?!\p{L})/iu', $replacement, $normalized) ?? $normalized;
        }

        foreach (self::SLANG_WORD_MAP as $slang => $replacement) {
            $normalized = preg_replace('/(?<!\p{L})'.preg_quote($slang, '/').'(?!\p{L})/iu', $replacement, $normalized) ?? $normalized;
        }

        $normalized = trim(preg_replace('/\s+/u', ' ', $normalized) ?? $normalized);
        $corrected = $this->correctQueryTypos($normalized, $repository);

        return [
            'clean' => $clean,
            'corrected' => $corrected,
            'isRecommendationIntent' => $isRecommendationIntent,
        ];
    }

    private function correctQueryTypos(string $text, ?DestinationRepository $repository): string
    {
        if ($repository === null) {
            return $text;
        }

        $tokens = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($tokens) || $tokens === []) {
            return $text;
        }

        $vocabularyWords = $this->buildOntologyTokenVocabulary($repository);
        if ($vocabularyWords === []) {
            return $text;
        }

        $knownTokens = array_merge(
            self::STOPWORDS,
            self::FREE_HINTS,
            self::CHEAP_HINTS,
            self::TOP_HINTS,
            self::LOWEST_RATING_HINTS,
            self::EXPENSIVE_HINTS,
            self::COMPLEX_HINTS,
            self::RECOMMENDATION_INTENT_HINTS,
            self::SOCIAL_CONTACT_HINTS,
            array_keys(self::TYPE_HINTS),
            array_values(self::SLANG_WORD_MAP),
            [
                'spot', 'foto', 'mana', 'saja', 'informasi', 'kabupaten', 'kota', 'sumatera', 'utara',
                'total', 'jumlah', 'statistik', 'berapa', 'semua', 'seluruh', 'tercatat', 'murah',
                'gratis', 'terdekat', 'dekat', 'buka', 'tutup', 'jam', 'harga', 'tiket', 'alamat',
            ],
        );

        foreach (self::ATTRIBUTE_HINTS as $hint => $aliases) {
            $knownTokens[] = $hint;
            foreach ($aliases as $alias) {
                if (is_string($alias)) {
                    $knownTokens[] = $alias;
                }
            }
        }

        foreach ($this->ontologyAttributeLabels($repository) as $label) {
            $knownTokens[] = $label;
        }

        $knownTokens = array_merge(
            ...array_map(
                static fn (string $knownToken): array => preg_split(
                    '/[^\p{L}\p{N}]+/u',
                    mb_strtolower($knownToken, 'UTF-8'),
                    -1,
                    PREG_SPLIT_NO_EMPTY,
                ) ?: [],
                array_values(array_filter($knownTokens, 'is_string')),
            ),
        );
        $knownTokens = array_values(array_unique($knownTokens));

        $correctedTokens = $tokens;
        $vocabEntries = [];
        foreach ($vocabularyWords as $word => $freq) {
            $vocabEntries[] = ['token' => (string) $word, 'frequency' => $freq];
        }

        $typoCorrector = null;
        if (class_exists(TypoCorrector::class)) {
            $typoCorrector = app()->bound(TypoCorrector::class)
                ? app(TypoCorrector::class)
                : new TypoCorrector;
        }

        foreach ($tokens as $index => $token) {
            $stripped = trim($token, ".,!?;:\"'()[]{}");
            if ($stripped === '' || mb_strlen($stripped, 'UTF-8') < 4 || ctype_digit($stripped)) {
                continue;
            }

            if (in_array($stripped, $knownTokens, true) || isset($vocabularyWords[$stripped])) {
                continue;
            }

            $replacement = null;
            if ($typoCorrector instanceof TypoCorrector) {
                $result = $typoCorrector->correct([$stripped], $vocabEntries);
                $candidate = $result['tokens'][0] ?? $stripped;
                if ($candidate !== $stripped && isset($vocabularyWords[$candidate])) {
                    $replacement = $candidate;
                }
            }

            if ($replacement === null) {
                $replacement = $this->fuzzyMatchToken($stripped, array_keys($vocabularyWords));
            }

            if ($replacement !== null && $replacement !== $stripped) {
                $correctedTokens[$index] = str_replace($stripped, $replacement, $token);
            }
        }

        return implode(' ', $correctedTokens);
    }

    /**
     * @return array<string, int>
     */
    private function buildOntologyTokenVocabulary(DestinationRepository $repository): array
    {
        $vocabulary = [];
        $facets = $repository->facets();
        $regions = $facets['kabupaten'] ?? [];

        foreach (is_array($regions) ? array_keys($regions) : [] as $region) {
            if (! is_string($region)) {
                continue;
            }

            $words = preg_split('/[^\p{L}\p{N}-]+/u', mb_strtolower($region, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($words as $word) {
                if (mb_strlen($word, 'UTF-8') >= 4 && ! in_array($word, ['kabupaten', 'kota'], true)) {
                    $vocabulary[$word] = ($vocabulary[$word] ?? 0) + 5;
                }
            }
        }

        foreach ($repository->all() as $destination) {
            if (! $destination instanceof Destination) {
                continue;
            }

            $nameText = trim(($destination->name ?? '').' '.($destination->alternativeName ?? ''));
            $words = preg_split('/[^\p{L}\p{N}-]+/u', mb_strtolower($nameText, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($words as $word) {
                if (mb_strlen($word, 'UTF-8') >= 4) {
                    $vocabulary[$word] = ($vocabulary[$word] ?? 0) + 2;
                }
            }
        }

        foreach ($this->ontologyAttributeLabels($repository) as $label) {
            $words = preg_split('/[^\p{L}\p{N}-]+/u', mb_strtolower($label, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($words as $word) {
                if (mb_strlen($word, 'UTF-8') >= 4) {
                    $vocabulary[$word] = ($vocabulary[$word] ?? 0) + 2;
                }
            }
        }

        return $vocabulary;
    }

    /**
     * @param  list<string>  $candidates
     */
    private function fuzzyMatchToken(string $token, array $candidates): ?string
    {
        if (strlen($token) > 50 || $token === '') {
            return null;
        }

        $bestCandidate = null;
        $bestScore = 0.0;
        $tokenCompact = str_replace('-', '', $token);
        $maxDistance = strlen($tokenCompact) <= 5 ? 1 : 2;

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '' || strlen($candidate) > 50) {
                continue;
            }

            $candidateCompact = str_replace('-', '', $candidate);
            if ($tokenCompact === $candidateCompact) {
                return $candidate;
            }

            $distance = levenshtein($tokenCompact, $candidateCompact);
            similar_text($tokenCompact, $candidateCompact, $similarity);

            if (($distance <= $maxDistance && $similarity >= 75.0) || $similarity >= 82.0) {
                $score = $similarity - ($distance * 5.0);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestCandidate = $candidate;
                }
            }
        }

        return $bestCandidate;
    }

    /**
     * @param  list<array{role: string, text: string}>  $history
     * @return array{region: ?string, types: list<string>}
     */
    private function extractHistoryContext(array $history, DestinationRepository $repository): array
    {
        $recentUserMessages = collect(array_slice($history, -8))
            ->filter(fn (array $item): bool => ($item['role'] ?? '') === 'user' && is_string($item['text'] ?? null))
            ->reverse()
            ->take(4);

        $region = null;
        $types = [];

        foreach ($recentUserMessages as $item) {
            $prev = $this->normalizeUserQuery((string) $item['text'], $repository)['corrected'];

            if ($region === null) {
                $region = $this->extractRegion($prev, $repository);
            }

            if ($types === []) {
                $types = $this->extractTypes($prev);
            }

            if ($region !== null && $types !== []) {
                break;
            }
        }

        return [
            'region' => $region,
            'types' => $types,
        ];
    }

    /**
     * @param  list<string>  $phrases
     */
    private function hasWordOrPhrase(string $message, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (preg_match('/(?<!\p{L})'.preg_quote($phrase, '/').'(?!\p{L})/iu', $message) === 1) {
                return true;
            }
        }

        return false;
    }

    private function extractRegion(string $message, DestinationRepository $repository): ?string
    {
        $regions = array_keys($repository->facets()['kabupaten'] ?? []);
        usort($regions, fn (string $first, string $second): int => mb_strlen($second) <=> mb_strlen($first));

        foreach ($regions as $region) {
            if ($region !== '' && str_contains($message, Str::lower($region))) {
                return $region;
            }
        }

        foreach ($regions as $region) {
            $short = trim((string) preg_replace('/^(?:kabupaten|kota)\s+/iu', '', $region));
            if ($short !== '' && mb_strlen($short, 'UTF-8') >= 4
                && preg_match('/(?<!\p{L})'.preg_quote(Str::lower($short), '/').'(?!\p{L})/iu', $message) === 1) {
                return $region;
            }
        }

        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $message, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($tokens as $token) {
            if (strlen($token) < 4 || strlen($token) > 50) {
                continue;
            }

            foreach ($regions as $region) {
                $short = Str::lower(trim((string) preg_replace('/^(?:kabupaten|kota)\s+/iu', '', $region)));
                if ($short === '' || strlen($short) > 50) {
                    continue;
                }

                $maxDistance = strlen($token) <= 5 ? 1 : 2;
                $distance = levenshtein($token, $short);
                similar_text($token, $short, $similarity);
                if (($distance <= $maxDistance && $similarity >= 75.0) || $similarity >= 82.0) {
                    return $region;
                }
            }
        }

        return null;
    }

    private function extractRequestedLimit(string $lower, int $default = 5, int $max = 10): int
    {
        $max = max(1, $max);
        $default = min($max, max(0, $default));
        $numberWords = [
            'satu' => 1,
            'dua' => 2,
            'tiga' => 3,
            'empat' => 4,
            'lima' => 5,
            'enam' => 6,
            'tujuh' => 7,
            'delapan' => 8,
            'sembilan' => 9,
            'sepuluh' => 10,
        ];
        $numberPattern = '(?:10|[1-9]|'.implode('|', array_keys($numberWords)).')';
        $pattern = '/(?<![\p{L}\p{N}.,])(?<number>'.$numberPattern.')(?![\p{L}\p{N}]|[.,]\d)/iu';
        preg_match_all($pattern, $lower, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches['number'] ?? [] as [$number, $offset]) {
            $number = Str::lower($number);
            $after = substr($lower, $offset + strlen($number));
            $before = substr($lower, max(0, $offset - 24), min(24, $offset));
            if (preg_match('/^\s*(?:rb|ribu|k|jam|hari|malam|bintang|rating|km)\b/iu', $after) === 1
                || preg_match('/\b(?:rp|harga|rating|bintang)\s*$/iu', $before) === 1
            ) {
                continue;
            }

            $value = ctype_digit($number) ? (int) $number : ($numberWords[$number] ?? 0);

            return min($max, max(1, $value));
        }

        return $default;
    }

    private function isComplexNarrativeQuery(string $lower): bool
    {
        return $this->hasAny($lower, self::COMPLEX_HINTS);
    }

    /**
     * @return list<string>
     */
    private function extractAttributeTerms(string $message, DestinationRepository $repository): array
    {
        $terms = [];
        $message = Str::lower($message);

        foreach (self::ATTRIBUTE_HINTS as $hint => $aliases) {
            if (str_contains($message, $hint)) {
                $terms = [...$terms, ...$aliases];
            }
        }

        foreach ($this->ontologyAttributeLabels($repository) as $label) {
            $normalizedLabel = Str::lower($label);
            if (! in_array($normalizedLabel, ['tempat wisata', 'wisata', 'destinasi'], true)
                && str_contains($message, $normalizedLabel)) {
                $terms[] = $normalizedLabel;
            }
        }

        return array_values(array_unique(array_filter($terms, 'is_string')));
    }

    /**
     * @return list<string>
     */
    private function ontologyAttributeLabels(DestinationRepository $repository): array
    {
        $labels = [];
        foreach ($repository->all() as $destination) {
            if (! $destination instanceof Destination) {
                continue;
            }

            foreach ([$destination->activities, $destination->facilities] as $values) {
                if (! is_array($values)) {
                    continue;
                }

                foreach (array_filter($values, 'is_string') as $label) {
                    $label = trim($label);
                    if ($label !== '') {
                        $labels[] = $label;
                    }
                }
            }
        }

        return array_values(array_unique($labels));
    }

    /**
     * @param  list<string>  $terms
     */
    private function matchesAttributeTerms(Destination $destination, array $terms): bool
    {
        $searchableAttributes = Str::lower(implode(' ', [
            ...array_filter($destination->facilities, 'is_string'),
            ...array_filter($destination->activities, 'is_string'),
            ...array_filter($destination->keywords, 'is_string'),
            $destination->shortDescription,
            $destination->fullDescription,
        ]));

        foreach ($terms as $term) {
            if (is_string($term) && $term !== '' && str_contains($searchableAttributes, Str::lower($term))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function extractTypes(string $message): array
    {
        $types = [];

        foreach (self::TYPE_HINTS as $hint => $type) {
            if (str_contains($message, $hint) && ! in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        return $types;
    }

    /**
     * @param  Collection<int, Destination>  $destinations
     * @return Collection<int, Destination>
     */
    private function findMentionedDestinations(string $message, Collection $destinations): Collection
    {
        $matches = $destinations->filter(function (Destination $destination) use ($message): bool {
            $name = Str::lower($destination->name ?? '');
            $alternative = Str::lower($destination->alternativeName ?? '');

            return ($name !== '' && str_contains($message, $name))
                || (mb_strlen($alternative) >= 4 && str_contains($message, $alternative));
        });

        if ($matches->isNotEmpty()) {
            return $matches->sortBy(fn (Destination $destination): int => -mb_strlen($destination->name ?? ''))->values();
        }

        $query = preg_replace(
            '/\b(info|informasi|tiket|harga|alamat|jadwal|profil|jelaskan|tentang|destinasi|wisata|di|ke|dong|tolong|please)\b/u',
            ' ',
            $message,
        ) ?? $message;
        $query = trim(preg_replace('/\s+/u', ' ', $query) ?? $query);

        if (mb_strlen($query) < 5) {
            return collect();
        }

        $candidates = $destinations->map(function (Destination $destination) use ($query): array {
            $name = Str::lower($destination->name ?? '');
            similar_text($query, $name, $similarity);

            return ['destination' => $destination, 'similarity' => $similarity];
        })->filter(fn (array $candidate): bool => $candidate['similarity'] >= 72.0);

        $best = $candidates->sortByDesc('similarity')->first();

        return $best !== null ? collect([$best['destination']]) : collect();
    }

    private function isGreeting(string $message): bool
    {
        $normalized = trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $message) ?? $message);

        return in_array($normalized, [
            'halo', 'hai', 'hi', 'hello', 'selamat pagi', 'selamat siang', 'selamat sore',
            'selamat malam', 'apa kabar',
        ], true);
    }

    private function isDestinationDetailIntent(string $message): bool
    {
        return $this->hasAny($message, [
            'info', 'informasi', 'detail', 'tentang', 'jam buka', 'alamat', 'harga tiket', 'jadwal',
            'profil', 'jelaskan', 'ceritakan', 'berapa harga', 'kapan buka',
        ]) || $this->isSocialContactIntent($message);
    }

    private function isSocialContactIntent(string $message): bool
    {
        return $this->hasWordOrPhrase($message, self::SOCIAL_CONTACT_HINTS);
    }

    /**
     * @param  list<string>  $hints
     */
    private function hasAny(string $message, array $hints): bool
    {
        foreach ($hints as $hint) {
            if (str_contains($message, $hint)) {
                return true;
            }
        }

        return false;
    }

    private function isFree(Destination $destination): bool
    {
        foreach ($destination->prices ?? [] as $price) {
            if (in_array(Str::lower(trim((string) $price)), self::FREE_HINTS, true)) {
                return true;
            }
        }

        return ($destination->lowestPrice() ?? null) === 0.0;
    }

    private function endpoint(): string
    {
        $endpoint = (string) config('services.gemini.endpoint');
        $model = (string) config('services.gemini.model', 'gemini-3.5-flash-lite');

        return $endpoint.'/models/'.$model.':generateContent';
    }

    /**
     * @param  list<array{role: string, text: string}>  $history
     * @return list<array{role: string, parts: list<array{text: string}>}>
     */
    private function contents(string $message, array $history): array
    {
        $contents = collect(array_slice($history, -6))
            ->map(fn (array $item): array => [
                'role' => $item['role'],
                'parts' => [['text' => $item['text']]],
            ])
            ->values()
            ->all();

        $contents[] = [
            'role' => 'user',
            'parts' => [['text' => $message]],
        ];

        return $contents;
    }

    /**
     * @param  array<string, mixed>  $intent
     * @param  Collection<int, Destination>  $candidates
     */
    private function systemInstruction(
        string $message,
        array $intent,
        Collection $candidates,
        DestinationRepository $repository,
    ): string {
        $candidateRule = $intent['isRecommendation']
            ? 'Untuk permintaan rekomendasi, berikan tepat '.min($intent['limit'], $candidates->count())
                .' destinasi berbeda (atau semua kandidat jika jumlahnya lebih sedikit), tulis nama setiap destinasi, dan jangan menambahkan destinasi lain.'
            : 'Gunakan hanya kandidat terpilih di konteks untuk menjawab pertanyaan. Jangan membuat nama atau fakta destinasi baru.';

        return <<<PROMPT
Anda adalah WayFinder AI, pemandu wisata digital untuk destinasi di Sumatera Utara.
Anda memahami bahasa gaul Indonesia, singkatan chat sehari-hari, maupun kesalahan ketik pengguna dengan ramah dan santai, tanpa kaku.
Selalu perhatikan konteks pesan-pesan sebelumnya pada riwayat percakapan (contents) ketika pengguna mengajukan pertanyaan lanjutan seperti "yang dekat situ apa?", "bandingkan keduanya", atau "buatkan itinerary-nya".
Jawab dengan ramah, alami, variatif, ringkas, dan informatif; hindari pembuka atau pola kalimat yang selalu sama.
Gunakan hanya data dari konteks ontologi di bawah. Jika data tidak tersedia, katakan terus terang
bahwa informasinya belum tercatat dan jangan mengarang angka, alamat, atau nomor kontak.
Untuk pertanyaan Instagram atau kontak, sebutkan akun/URL Instagram dan kontak resmi jika tercantum di konteks; jika kosong, jelaskan bahwa data tersebut belum tersedia dan jangan menebaknya.
Jawab dalam bahasa yang digunakan pengguna. Jangan menampilkan data pribadi atau kredensial.
{$candidateRule}
Untuk setiap destinasi rekomendasi, sebutkan alasan relevansinya dengan pertanyaan, misalnya aktivitas/fasilitas yang cocok atau ratingnya. Pastikan penjelasan tetap bersumber pada data ontologi.

KONTEKS ONTOLOGI (ringkas):
<ontology_context>
{$this->context($message, $intent, $candidates, $repository)}
</ontology_context>
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $intent
     * @param  Collection<int, Destination>  $candidates
     */
    private function context(
        string $message,
        array $intent,
        Collection $candidates,
        DestinationRepository $repository,
    ): string {
        $destinations = $repository->all();
        $facets = $repository->facets();

        $lines = [
            'Permintaan: '.$message,
            'Total destinasi: '.$destinations->count(),
            'Jumlah per kategori: '.collect($facets['types'] ?? [])
                ->map(fn (int $count, string $type): string => $type.'='.$count)
                ->implode(', '),
            'Kabupaten/kota tercatat: '.count($facets['kabupaten'] ?? []),
            'Rata-rata rating: '.($facets['average_rating'] ?? 'tidak tersedia')
                .' dari '.($facets['rated_count'] ?? 0).' destinasi berating.',
            '',
            'Kandidat terpilih sesuai filter dan urutan permintaan (batas '.$intent['limit'].'):',
        ];

        foreach ($candidates as $destination) {
            $lines[] = $this->describe($destination);
        }

        if ($candidates->isEmpty()) {
            $lines[] = 'Tidak ada kandidat yang cocok. Jangan mengarang destinasi; sampaikan bahwa data yang sesuai belum ditemukan.';
        }

        return implode("\n", $lines);
    }

    private function describe(Destination $destination): string
    {
        $facilities = $destination->facilities !== []
            ? implode(', ', array_filter($destination->facilities, 'is_string'))
            : 'tidak tercatat';
        $activities = $destination->activities !== []
            ? implode(', ', array_filter($destination->activities, 'is_string'))
            : 'tidak tercatat';
        $keywords = $destination->keywords !== []
            ? implode(', ', array_filter($destination->keywords, 'is_string'))
            : 'tidak tercatat';
        $types = $destination->types !== [] ? implode(', ', $destination->types) : 'tidak tercatat';
        $prices = $destination->priceLabels();
        $description = implode(' | ', array_values(array_filter([
            trim($destination->shortDescription),
            trim($destination->fullDescription),
        ], static fn (string $value): bool => $value !== '')));

        return sprintf(
            "- %s (ID %s)\n  Nama alternatif: %s\n  Kategori: %s | Wilayah: %s | Koordinat: %s | Rating: %s\n  Harga tiket: %s | Jam buka: %s\n  Alamat: %s | Petunjuk lokasi: %s\n  Fasilitas: %s\n  Aktivitas: %s\n  Instagram: %s | Telepon: %s | Email/kontak resmi: %s | Situs web: %s\n  Media: %s | Kata kunci: %s\n  Deskripsi: %s",
            $destination->name,
            $destination->id,
            $destination->alternativeName !== '' ? $destination->alternativeName : 'tidak tercatat',
            $types,
            $destination->location !== '' ? $destination->location : $destination->province(),
            $destination->hasCoordinates()
                ? $destination->latitude.', '.$destination->longitude
                : 'tidak tercatat',
            $destination->ratingLabel(),
            $prices !== [] ? implode(', ', $prices) : 'tidak tercatat',
            $destination->openingHoursLabel(),
            $destination->address !== '' ? $destination->address : 'tidak tercatat',
            $destination->directions !== '' ? $destination->directions : 'tidak tercatat',
            $facilities,
            $activities,
            $destination->instagram !== '' ? $destination->instagram : 'tidak tercatat',
            $destination->phone !== '' ? $destination->phone : 'tidak tercatat',
            $destination->email !== '' ? $destination->email : 'tidak tercatat',
            $destination->website !== '' ? $destination->website : 'tidak tercatat',
            $destination->media !== '' ? $destination->media : 'tidak tercatat',
            $keywords,
            $description !== '' ? $description : 'tidak tercatat',
        );
    }
}
