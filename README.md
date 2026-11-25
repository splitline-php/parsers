# splitline/parsers

Pure PHP binary FIT file parser (`FitParser`) returning immutable `Splitline\Core\Data\ActivityStreamData` DTOs with downsampled chart series, Douglas-Peucker polylines, laps, splits, run/walk segments, jumps, and session summaries.

## Installation

```bash
composer require splitline/parsers
```

Requires `ext-zip` for zipped `.fit` archives.

## Usage

```php
use Splitline\Parsers\Fit\FitParser;

$parser = new FitParser();
$stream = $parser->parseBinary(file_get_contents('activity.fit'));
```

## Contributing

This repository is a **read-only subtree split**. Please submit all issues and pull requests to [splitline-php/ingest](https://github.com/splitline-php/ingest).

## License

MIT — see [LICENSE](LICENSE).
