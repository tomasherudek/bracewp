# Test fixtures: the nasty datasets

This directory will hold the datasets that prove correctness of the hard components, above all `Brace\Services\Replacer`. They arrive together with the implementations they test.

Planned datasets:

* Corrupted serialized strings (wrong length counts, truncated payloads) that must survive untouched.
* Nested serialization: serialized data inside JSON, JSON inside serialized arrays, several levels deep.
* utf8mb4 and emoji content, multibyte boundaries inside serialized string lengths.
* Huge autoloaded options for the DB hygiene checks and batch processing.
* Round-trip sets: replace, then replace back, then byte-compare with the original.
* Backup dump, restore, byte-compare sets for the Backup service.
