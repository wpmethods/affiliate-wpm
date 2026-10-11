# Parsedown — bundled version and local patches

**Upstream:** https://github.com/erusev/parsedown
**Bundled version:** 1.7.4 (upgraded from 1.6.0 in Click Shop 3.1.7)
**Licence:** MIT — see `Parsedown-LICENSE.txt`

The library lives in `ParsedownModern.php`. `Parsedown.php` is the Plugin Update
Checker's loader shim and is not upstream Parsedown.

## Upstream verification

`ParsedownModern.php` is upstream `Parsedown.php` from tag `1.7.4`, verified by
downloading it from two independent sources and confirming the digests match:

| Source | SHA-256 |
|---|---|
| `raw.githubusercontent.com/erusev/parsedown/1.7.4/Parsedown.php` | `af4a4b29f38b5a00b003a3b7a752282274c969e42dee88e55a427b2b61a2f38f` |
| `codeload.github.com/erusev/parsedown` tarball, tag `1.7.4` | `af4a4b29f38b5a00b003a3b7a752282274c969e42dee88e55a427b2b61a2f38f` |

## Local patches

Exactly **two lines** differ from pristine upstream 1.7.4. Both are the same
PHP 8.4 compatibility fix, and both were already present in the previously
bundled build (which was 1.6.0 plus backports, not stock 1.6.0).

```diff
@@ line 715 @@
-    protected function blockSetextHeader($Line, array $Block = null)
+    protected function blockSetextHeader($Line, ?array $Block = null)

@@ line 853 @@
-    protected function blockTable($Line, array $Block = null)
+    protected function blockTable($Line, ?array $Block = null)
```

**Why:** PHP 8.4 deprecates implicit nullable parameter types. `array $Block = null`
emits *"Implicit nullable parameter type is deprecated, use `?array` instead"* on
every call. Upstream 1.7.x has not been updated for this; the fix is upstream's
own recommended form and is valid on PHP 7.1+ (this theme requires PHP 7.4).

Dropping these patches would reintroduce PHP 8.4 deprecation notices.

## Note for a future maintainer

Safe mode (`setSafeMode(true)`), added in 1.7.x, is **not** enabled. The Plugin
Update Checker calls `Parsedown::instance()->text($changelog)` with defaults, so
raw HTML in changelog markdown is still rendered as HTML. That is the behaviour
that existed before this upgrade and it was deliberately left unchanged here.
Enabling safe mode would harden changelog rendering further but changes output,
so it belongs in a release where that behaviour change can be tested.

## Upgrade procedure

1. Download `Parsedown.php` from the desired upstream tag.
2. Verify the digest against a second source.
3. Save as `ParsedownModern.php`.
4. Re-apply the two patches above if upstream still uses implicit nullables.
5. Update `Parsedown-LICENSE.txt` if upstream's licence changed.
