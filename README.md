# Post Type Admin Columns Toggle

Adds configurable admin list-table columns for any post type based on:

- Advanced Custom Fields (ACF) field names
- Taxonomies attached to the post type

## What it does

- Adds a settings page at **Settings > Post Type Columns**.
- Lets you choose which ACF fields and taxonomies should appear as admin columns per post type.
- Selected columns are shown on post-type list pages.
- Users can still toggle those columns on/off per-screen using **Screen Options**.

## Install

1. Copy this folder into `wp-content/plugins/`.
2. Activate **Post Type Admin Columns Toggle**.
3. Open **Settings > Post Type Columns** and choose your columns.

## Notes

- If ACF is not active, ACF field selection is unavailable.
- Taxonomy columns registered natively by WordPress are supported. Visibility stays user-controllable through Screen Options.
