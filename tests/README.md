# Beacon Test Commands

Quick run commands for this plugin:

- Unit/default suite (excludes `requires-craft`):
  - `./plugins/beacon/vendor/bin/phpunit -c ./plugins/beacon/phpunit.xml`
- Requires-Craft integration tests:
  - `./plugins/beacon/vendor/bin/phpunit -c ./plugins/beacon/phpunit.xml --group requires-craft`
- GEO policy + freshness targeted tests:
  - `./plugins/beacon/vendor/bin/phpunit -c ./plugins/beacon/phpunit.xml --filter "(GeoMarkdownExportServicePolicyTest|RawResponseConditionalRequestTest|FeedServiceTest)"`
- GEO controller integration targeted tests:
  - `./plugins/beacon/vendor/bin/phpunit -c ./plugins/beacon/phpunit.xml --group requires-craft --filter "(GeoExportControllerPolicyTest|GeoExportNoindexTest)"`

