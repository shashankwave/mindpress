# MindPress – Mind Map to Post
[![Release](https://img.shields.io/github/v/release/shashankwave/mindpress?include_prereleases&label=release)](https://github.com/shashankwave/mindpress/releases/latest)
![License](https://img.shields.io/badge/license-GPL--2.0%2B-blue.svg)
![WordPress](https://img.shields.io/badge/WordPress-%E2%89%A5%206.0-21759b)
![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%207.4-777bb3)

Plan blog posts visually **inside WordPress**. Build a simple mind map, then **Generate a draft** or **Insert** directly into the current post. Comes with autosave, search, color tags, expand/collapse, export/import JSON, and quick structure controls.

> Works in both **Mind Maps** (custom post type) and regular **Posts** (classic or block editor).

## ✨ Features
- Visual mind-map builder UI (no external service)
- Autosave (AJAX) with status indicator (Saving… → Saved ✓)
- Generate a new Draft Post, or Insert into current post
- Add child/sibling, move (↑/↓), indent/outdent, delete
- Color tags, collapse/expand, search
- Export/Import JSON; data saved as `_mp_tree` meta

## 📸 Screenshots
<!-- Add screenshots under assets/ and link them here -->
<!-- ![Builder UI](assets/screenshot-1.png) -->

## 🛠 Requirements
WordPress 6.0+, PHP 7.4+

## 🚀 Installation
Download ZIP from Releases and upload in WP Admin → Plugins → Add New → Upload.
Or copy `mindpress/` to `wp-content/plugins/` and activate.

## ⚡ Quick Start
Create a Mind Map or open a Post → build the outline → autosave runs → Generate Draft or Insert.

## 📝 Output
Headings follow depth (H2/H3/H4). Notes become paragraphs.

## 🔄 Data format
Saved as JSON under `_mp_tree`. View with:
`wp post meta get <ID> _mp_tree`

## ❓ FAQ / Troubleshooting
If UI doesn’t load, hard refresh (Cmd+Shift+R). Check DevTools → Network for `mp_save_tree` 200 responses.

## 🧩 Roadmap
Drag-and-drop, keyboard shortcuts, per-node checklists, AI outline suggestions.

## 🤝 Contributing
PRs welcome. Branch from main and describe changes.

## 📄 License
GPL-2.0-or-later.
