# Shortcode to Blocks Pro release process

## Normal private release (after 1.0.5)

1. Update the plugin header, `STBP_VERSION`, `readme.txt` stable tag, changelog,
   and upgrade notice.
2. Build the production archive:

   ```bash
   ./build-release.sh
   ```

3. Verify the generated `dist/shortcode-to-blocks-pro-<version>.zip`, including
   its archive root, PHP syntax, size, and SHA-256 checksum.
4. From the site's `astro-site` directory, dry-run the private upload, then use
   its printed checksum-bound confirmation phrase for the production upload:

   ```bash
   npm run publish:pro-release -- /absolute/path/to/the/versioned.zip
   npm run publish:pro-release -- /absolute/path/to/the/versioned.zip --apply --confirm-production-upload
   ```

5. Mark the release current through that publisher and verify the licensed
   update path from a test WordPress installation.
6. Commit, tag, and push the source. Do not attach the production ZIP to a public
   GitHub release after the 1.0.5 bridge.

The update service validates the license and site before returning a 48-hour
download token. The download route validates the entitlement again, then issues
a 60-second signed URL for the private Supabase Storage object.

## One-time 1.0.5 bridge release

Existing 1.0.4 installations still discover releases through GitHub. For 1.0.5
only, complete the private upload first, deploy and test the site endpoints, and
then attach the exact same verified 1.0.5 ZIP to the GitHub release. This moves
installed copies to the private updater without requiring customers to reinstall.

Before publishing the bridge:

- Confirm `/api/pro-update-manifest` returns 1.0.5 and a private download URL for
  an authorized test license.
- Confirm invalid, expired, and over-limit licenses receive no update metadata.
- Confirm `/api/pro-download` rejects tampered/expired tokens and inactive
  entitlements.
- Test a real WordPress upgrade from 1.0.4 to 1.0.5 and confirm the plugin remains
  active afterward.

Keep the GitHub 1.0.5 asset available during the adoption window. Future paid
distribution belongs only in the private `pro-releases` bucket.
