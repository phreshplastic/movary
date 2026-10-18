<?php declare(strict_types=1);

namespace Tests\Unit\Movary\Service\Journal;

use Movary\Api\Tmdb\TmdbClient;
use Movary\Service\ImageUrlService;
use Movary\Service\Journal\LibraryMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(LibraryMetadata::class)]
class LibraryMetadataTest extends TestCase
{
    public function testTvCreatorsAndAggregateRolesKeepTheirScope() : void
    {
        $client = $this->createMock(TmdbClient::class);
        $client->expects(self::once())->method('get')->with('/tv/42', ['append_to_response' => 'aggregate_credits,keywords,external_ids'])->willReturn([
            'id' => 42, 'name' => 'Fixture show', 'first_air_date' => '2020-01-01', 'original_language' => 'en',
            'created_by' => [['id' => 8, 'name' => 'A creator']],
            'aggregate_credits' => ['cast' => [['id' => 9, 'name' => 'An actor', 'roles' => [['character' => 'A role', 'episode_count' => 8]]]],
                'crew' => [['id' => 10, 'name' => 'A director', 'jobs' => [['job' => 'Director', 'episode_count' => 2]]]]],
            'keywords' => ['results' => [['id' => 12, 'name' => 'A keyword']]], 'seasons' => [['season_number' => 1]],
        ]);
        $metadata = (new LibraryMetadata($client, $this->createStub(ImageUrlService::class)))->fetch('tv', 42);
        self::assertSame(['Creator', 'Cast', 'Director'], array_column($metadata['people'], 'role'));
        self::assertSame(['series', 'aggregate', 'aggregate'], array_column($metadata['people'], 'scope'));
        self::assertSame('A keyword', $metadata['keywords'][0]['name']);
        self::assertSame('2020', $metadata['year']);
    }

    public function testProviderOutagesReturnNoSensitiveExceptionDetails() : void
    {
        $client = $this->createStub(TmdbClient::class);
        $client->method('get')->willThrowException(new \RuntimeException('api_key=private-fixture'));
        $metadata = new LibraryMetadata($client, $this->createStub(ImageUrlService::class));
        try {
            $metadata->search('A title');
            self::fail('A provider error must be reported.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('private-fixture', $e->getMessage());
            self::assertStringContainsString('library is still available', $e->getMessage());
        }
    }
}
