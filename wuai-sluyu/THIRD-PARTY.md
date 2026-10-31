# Settings framework provenance

The settings integration uses the framework files in the Zibll source supplied by the site owner:

- `inc/codestar-framework/`: Codestar Framework 2.2.0, copyright 2015–2020 Codestar. Original package headers are retained. Samples, welcome pages, SCSS and unused translations are omitted.
- `inc/csf-framework/classes/admin-options.class.php`, `fields/upload`, `fields/text`, `fields/select`, and `assets/css/style.css`: Zibll settings overrides supplied with that source. Original author notices are retained.
- `assets/vendor/font-awesome/`: Font Awesome 4.7.0 from the same supplied distribution; font license SIL OFL 1.1, CSS license MIT (as stated in its stylesheet header).

Integration changes:

- Load the actual override class before CSF initialization, keep `wuai_sluyu_options` as the database option and register this theme's fields using `CSF::createOptions` / `CSF::createSection`.
- Replace the Zibll-specific font URL with bundled local Font Awesome files and declare WordPress script dependencies.
- Check settings capabilities on writes and restrict CSF backup endpoints to registered option groups. Theme settings are sanitized and backed up consistently.
- Recover the save controls after failed AJAX requests and support import/reset outside the WordPress Customizer.
- Adapt the menu toggle and sticky-footer behavior from the Zibll settings script in `assets/js/admin.js`; do not load its payment, authorization or Zibll update handlers.
- Layer a small theme adaptation stylesheet over the supplied Zibll stylesheet. The header identifies this theme as WUAI.

This integration provides the settings framework and this theme's settings. It does not claim to implement the full Zibll theme, its licensed services, commerce features or other business modules.
