<?php declare(strict_types=1);

namespace Tests\Unit\Movary\Api\Tmdb;

use Movary\Api\Tmdb\Cache\TmdbIsoLanguageCache;
use Movary\Api\Tmdb\TmdbApi;
use Movary\Api\Tmdb\TmdbClient;
use Movary\ValueObject\Year;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TmdbApi::class)]
class TmdbTvSearchTest extends TestCase
{
    public function testTvSearchUsesFirstAirYearAndCorrectEndpoint() : void
    {
        $client = $this->createMock(TmdbClient::class);
        $client->expects(self::once())->method('get')->with('/search/tv', [
            'query' => 'Example+Show', 'page' => '2', 'first_air_date_year' => '2024',
        ])->willReturn(['results' => []]);
        $api = new TmdbApi($client, $this->createStub(TmdbIsoLanguageCache::class));

        self::assertSame(['results' => []], $api->searchTv('Example Show', Year::createFromString('2024'), 2));
    }
}
