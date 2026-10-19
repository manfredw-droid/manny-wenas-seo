# Finding #22 — repeated schema builds

`MWSEO_Schema::build()` starts a fresh node collection for each graph. Previously,
`publisher()` retained a function-static publisher across calls. Its cached logo
reference survived while the referenced ImageObject had been cleared from the
graph. A second build could therefore emit a dangling publisher logo reference.

The fix removes only that publisher cache. Each call reads the current supported
publisher configuration and registers its image through the existing `add()`
method. That method already merges nodes by `@id`, including when the logo is
also a featured image. No new cache, identifier, schema property or storage route
is introduced. Other schema builders and output serialization remain unchanged.

The class uses static builder methods and shared static nodes; constructing two
instances does not provide independent instance state. Successive builds through
either instance still reset and construct a complete graph. This fix does not
introduce or promise reentrant/concurrent graph construction within graph filters.

## Focused verification

Run `php tests/schema-repeated-build-wordpress.php /disposable/site/wp-load.php`.
Use an activated plugin, administrator ID 1 and a disposable database prefix
starting `solstice_schema22_`. The script refuses other prefixes, creates its own
article and two real GIF attachments, restores its options and deletes its own
fixtures. It uses actual WordPress image resolution, not an image-downsize stub.

On WordPress **6.4**, both **PHP 7.4.33** and **8.3.33** pass **193/193** checks.
The unchanged baseline fails 85 checks on each version: the first organization
graph has seven nodes including its logo; subsequent builds have six without it.
The fix keeps seven nodes and one logo across all four builds. Other failed
baseline assertions also expose the same stale publisher cache after changing
options; they are not 85 separate defects.

Coverage includes organization/person/hybrid publishers, absent or invalid logos,
missing person fallback, two instances, a shared featured-image/logo, changed
organization/logo/social profiles and updated attachment captions. Every build
checks unique IDs and resolved website/article/publisher/logo references. The
first organization graph matches the original after normalizing generated fixture
post/attachment/breadcrumb identifiers; expected production ID formats are also
asserted directly. Existing scoring and REST snapshot isolated regressions pass
unchanged on both PHP versions; scoring stays **1.2**.

No browser test, second WordPress version, multisite or persistent-cache stress
test is claimed. Removing the cache repeats publisher/image lookup per call;
native WordPress/options caches remain available. This bounded lookup cost was
not separately benchmarked. No release or Gold approval is implied.
