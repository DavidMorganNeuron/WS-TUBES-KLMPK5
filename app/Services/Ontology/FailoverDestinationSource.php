<?php

declare(strict_types=1);

namespace App\Services\Ontology;

use App\Data\Destination;
use App\Data\SearchPlan;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

final class FailoverDestinationSource implements DestinationSource
{
    private const PRIMARY_RETRY_INTERVAL_SECONDS = 30;

    private ?float $retryPrimaryAt = null;

    public function __construct(
        private readonly DestinationSource $primary,
        private readonly DestinationSource $fallback,
    ) {}

    /**
     * @return Collection<int, Destination>
     */
    public function all(): Collection
    {
        return $this->withFallback(static fn (DestinationSource $source): Collection => $source->all());
    }

    /**
     * @param  array{q?: string|null, jenis?: string|null, kabupaten?: string|null, provinsi?: string|null, sort?: string|null, dapatMelakukan?: string|null, memilikiFasilitas?: string|null, exact?: bool|int|string|null}  $filters
     * @return Collection<int, Destination>
     */
    public function search(array $filters = []): Collection
    {
        return $this->withFallback(static fn (DestinationSource $source): Collection => $source->search($filters));
    }

    public function planSearch(string $query, bool $exact = false): SearchPlan
    {
        return $this->withFallback(
            static fn (DestinationSource $source): SearchPlan => $source->planSearch($query, $exact),
        );
    }

    /**
     * @return list<string>
     */
    public function searchableTexts(): array
    {
        return $this->withFallback(static fn (DestinationSource $source): array => $source->searchableTexts());
    }

    /**
     * @param  array{q?: string|null, jenis?: string|null, kabupaten?: string|null, provinsi?: string|null, sort?: string|null, dapatMelakukan?: string|null, memilikiFasilitas?: string|null, exact?: bool|int|string|null}  $filters
     */
    public function paginate(array $filters = [], int $perPage = 9): LengthAwarePaginator
    {
        return $this->withFallback(
            static fn (DestinationSource $source): LengthAwarePaginator => $source->paginate($filters, $perPage),
        );
    }

    public function find(string $id): ?Destination
    {
        return $this->withFallback(static fn (DestinationSource $source): ?Destination => $source->find($id));
    }

    /**
     * @return Collection<int, Destination>
     */
    public function topRated(int $limit = 6): Collection
    {
        return $this->withFallback(static fn (DestinationSource $source): Collection => $source->topRated($limit));
    }

    /**
     * @return Collection<int, Destination>
     */
    public function related(Destination $destination, int $limit = 4): Collection
    {
        return $this->withFallback(
            static fn (DestinationSource $source): Collection => $source->related($destination, $limit),
        );
    }

    /**
     * @return Collection<int, Destination>
     */
    public function crossCategory(Destination $destination, int $limit = 4): Collection
    {
        return $this->withFallback(
            static fn (DestinationSource $source): Collection => $source->crossCategory($destination, $limit),
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function markers(array $filters = []): array
    {
        return $this->withFallback(static fn (DestinationSource $source): array => $source->markers($filters));
    }

    /**
     * @return array{total: int, types: array<string, int>, provinces: array<string, int>, kabupaten: array<string, int>, average_rating: float|null, rated_count: int, with_coordinates: int, with_media: int}
     */
    public function facets(): array
    {
        return $this->withFallback(static fn (DestinationSource $source): array => $source->facets());
    }

    /**
     * @return array{activities: list<string>, facilities: list<string>}
     */
    public function filterOptions(): array
    {
        return $this->withFallback(static fn (DestinationSource $source): array => $source->filterOptions());
    }

    /**
     * @return array{total: int, types: array<string, int>, provinces: array<string, int>, kabupaten: array<string, int>, average_rating: float|null, rated_count: int, with_coordinates: int, with_media: int, top_rated: Collection<int, Destination>, top_region: array{label: string, count: int}|null}
     */
    public function dashboard(): array
    {
        return $this->withFallback(static fn (DestinationSource $source): array => $source->dashboard());
    }

    /**
     * @return array{total: int, rated: int, unrated: int, average_rating: float|null, with_coordinates: int, with_media: int}
     */
    public function statistics(): array
    {
        return $this->withFallback(static fn (DestinationSource $source): array => $source->statistics());
    }

    /**
     * @return Collection<int, Destination>
     */
    public function nearby(Destination $destination, ?int $limit = null): Collection
    {
        return $this->withFallback(
            static fn (DestinationSource $source): Collection => $source->nearby($destination, $limit),
        );
    }

    /**
     * @return Collection<int, Destination>
     */
    public function similarByCategory(Destination $destination, int $limit = 4): Collection
    {
        return $this->withFallback(
            static fn (DestinationSource $source): Collection => $source->similarByCategory($destination, $limit),
        );
    }

    /**
     * @param  array{q?: string, jenis?: string, kabupaten?: string, provinsi?: string}  $criteria
     * @return list<array{id: string, name: string, types: list<string>, location: string, rating: float|null, price: float|null}>
     */
    public function retrieveForContext(array $criteria = [], int $limit = 5): array
    {
        return $this->withFallback(
            static fn (DestinationSource $source): array => $source->retrieveForContext($criteria, $limit),
        );
    }

    public function forgetCache(): void
    {
        $this->withFallback(static function (DestinationSource $source): null {
            $source->forgetCache();

            return null;
        });
        $this->fallback->forgetCache();
        $this->retryPrimaryAt = null;
    }

    /**
     * @template TValue
     *
     * @param  callable(DestinationSource): TValue  $operation
     * @return TValue
     */
    private function withFallback(callable $operation): mixed
    {
        if ($this->retryPrimaryAt !== null && microtime(true) < $this->retryPrimaryAt) {
            return $operation($this->fallback);
        }

        try {
            $result = $operation($this->primary);
            $this->retryPrimaryAt = null;

            return $result;
        } catch (OntologyUnavailableException $exception) {
            $this->retryPrimaryAt = microtime(true) + self::PRIMARY_RETRY_INTERVAL_SECONDS;
            Log::warning('Fuseki tidak tersedia; ontologi lokal digunakan sementara.', [
                'exception' => $exception::class,
            ]);

            return $operation($this->fallback);
        }
    }
}
