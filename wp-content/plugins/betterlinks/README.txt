=== BetterLinks – Link Shortener, Link Cloaking, Redirects, Affiliate Link Manager & MCP ===
Contributors: wpdevteam, re_enter_rupok, asif2bd, priyomukul, hasandev
Donate link: https://wpdeveloper.com
Tags: link shortener, affiliate links, redirects, link cloaking, url shortener
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.1.4
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Shorten, cloak, redirect & track every link in WordPress. Branded short URLs, click analytics, affiliate links & MCP access for AI assistants.

== Description ==

**BetterLinks is the complete link management plugin for WordPress.** Turn long, ugly URLs into short, branded, memorable links — then cloak them, redirect them, organize them and track every single click, all from one dashboard inside WordPress.

No third-party URL shortener. No monthly subscription for basic link tracking. Your links live on your own domain, and your click data stays in your own database.

[youtube https://www.youtube.com/watch?v=ZJqBrFhQC1A]

👉 [See All Features](https://betterlinks.io/features/) | [Documentation](https://betterlinks.io/docs/) | [Live Demo](https://betterlinks.io/) | [Upgrade to PRO](https://betterlinks.io/#pricing)

## 🤖 NEW: Manage Your Links With AI (MCP Connector)

BetterLinks now ships a built-in **MCP (Model Context Protocol) server**, so AI assistants like **Claude**, **ChatGPT**, **Claude Code**, **Cursor** and **VS Code** can work with your links directly — in plain English.

Ask your assistant to *"create a short link for this affiliate offer"*, *"which links got the most clicks last month?"* or *"put every Black Friday link in its own category"*, and it does the work inside your own dashboard.

* **Runs on your own site** — no hosted broker in between; link and analytics data is only shared with the AI assistant you connect, when that assistant uses a tool
* **14 built-in tools** — create, update and delete links, manage categories and tags, read click analytics, and read or update settings
* **Two ways to connect** — a one-time OAuth approval for Claude and ChatGPT, or a copy-paste command for Claude Code, Cursor and VS Code
* **Off by default** — nothing is exposed to anyone until you switch it on in **BetterLinks → MCP**
* **Scope-aware** — apps that ask for read-only access are enforced as read-only and clearly labelled in your dashboard
* **Revocable any time** — reset the connection token, or revoke a single connected app, with one click
* **Admin-scoped** — every action runs as the administrator who approved it and respects that account's permissions
* **Built-in health check** — a live "Test connection" round trip tells you exactly which step failed if a client can't connect

Built on the WordPress **Abilities API**, bundled with the plugin — there are no companion plugins to install.

## 🔗 Create Short Links In Seconds

Paste a long URL, pick a slug, hit publish. BetterLinks generates clean short links like `yoursite.com/go/deal` that are easy to share, easy to remember and impossible to mistype.

* **Instant URL shortener** — shorten any internal or external URL in a couple of clicks
* **Custom slugs** — write your own, or auto-generate from the title, target URL, or a random string
* **One-click copy & share** — grab any short link straight from the dashboard
* **Duplicate & bulk actions** — clone links, bulk delete with undo, drag-and-drop to reorder
* **Quick Link Creation** — create short links from outside the WordPress admin using an API key
* **Custom fields** — attach your own metadata to any link

## ↪️ Powerful Redirects & Link Cloaking

Send visitors exactly where you want, with the redirect type that fits the job.

* **301, 302 & 307 redirects** — permanent, temporary and method-preserving
* **Link cloaking (PRO)** — keep your branded short URL in the address bar the whole visit
* **Wildcard redirects** — match a whole path pattern with `/folder/*`
* **Parameter forwarding** — pass query strings straight through to the destination
* **Case-sensitive slugs** — treat `/Deal` and `/deal` as separate links when you need to
* **Nofollow & sponsored tags** — mark affiliate links correctly for search engines
* **Force HTTPS (PRO)** — every redirect goes out secure, whatever is stored on the link

## 📊 Real-Time Click Tracking & Link Analytics

Know which links actually work. BetterLinks records every click and turns it into reports you can act on — no external analytics account required.

* **Total & unique clicks** for every link
* **Real-time analytics dashboard** with an interactive date-range calendar
* **Traffic sources** — top referrers, social clicks, campaign performance
* **Geographic reports (PRO)** — see which countries your clicks come from
* **Browser, OS & device breakdown (PRO)**
* **Per-link analytics (PRO)** — a full report for any single link
* **Top & worst performers (PRO)** — find your winners and your dead weight
* **Bot filtering** — automated traffic is detected and kept out of your numbers
* **Exclude your own IP (PRO)** — stop your team's clicks from skewing the data
* **Google Analytics 4 & Facebook Pixel (PRO)** — push click events server-side

## 💰 Built For Affiliate Marketers

BetterLinks is a full affiliate link manager, not just a shortener.

* **Cloak affiliate links (PRO)** so long tracking URLs never appear in your content
* **Auto-Link Keywords (PRO)** — turn chosen keywords into affiliate links across your whole site automatically
* **Affiliate link disclosure (PRO)** — FTC-friendly notices via block, shortcode, or site-wide rule
* **Uncloak per link or category (PRO)** — stay compliant with Amazon Associates and similar programs
* **Sponsored & nofollow attributes** built into every link
* **Broken link scanner (PRO)** — catch dead affiliate links before they cost you commission

## 🗂️ Organize Hundreds Of Links Without The Mess

* **Categories & tags** with bulk assignment and per-term click stats
* **List view & board view** — pick the layout that suits how you work
* **Compact mode** — a dense, single-line list when you want to scan everything at a glance
* **Search, filter & sort** by category, tag, date, clicks or favorites
* **Favorites** — pin the links you touch every day
* **Role-based permissions (PRO)** — decide exactly who can create, edit, or view links and analytics

## 🚀 Marketing & Campaign Tools

* **UTM Builder** — attach campaign parameters to any link
* **Global UTM templates (PRO)** — save presets and apply them in bulk
* **Dynamic redirects (PRO)** — A/B split testing, geo-targeting, device targeting and time-based rules on a single short URL
* **Link scheduling & expiration (PRO)** — publish later, expire by date or click count, with a fallback URL
* **Password-protected links (PRO)** — gate a short link behind a password
* **Custom link previews (PRO)** — control the title, description and image when your link is shared on social
* **Custom domain (PRO)** — serve short links from your own branded domain
* **AI Bulk Link Generator (PRO)** — generate short links across many posts at once

## ⚡ Fast, Lightweight & Developer-Friendly

BetterLinks is engineered for speed. Redirects resolve from an optimized cache instead of hammering your database, so short links stay fast even with tens of thousands of them.

* Optimized queries and a JSON-backed redirect cache
* Works with any theme
* **Gutenberg block** and **Elementor** integration for inserting links while you write
* **Fluent Boards integration** — manage short links directly from your project tasks
* Full REST API for developers
* **MCP server & WordPress Abilities API support** — expose your links to AI agents and automations
* Translation-ready and WPML-compatible

## 🔄 Switching From Another Plugin?

Move your existing links across in one click — no CSV wrangling, no lost redirects.

* **Pretty Links** → BetterLinks
* **ThirstyAffiliates** → BetterLinks
* **Simple 301 Redirects** → BetterLinks
* Plus CSV import/export for links and click history

## 🏆 Why Choose BetterLinks

* **Your data stays yours** — links and analytics live in your own WordPress database
* **No per-click pricing** — unlike hosted URL shorteners
* **Built for scale** — thousands of links without slowing your site down
* **Actively maintained** by [WPDeveloper](https://wpdeveloper.com/)
* **Free forever core** — shortening, redirects, categories, analytics and migration are all in the free plugin

## 🔥 Upgrade To BetterLinks PRO

Unlock the full toolkit: link cloaking, dynamic redirects with A/B split testing, geo & device targeting, individual link analytics, Google Analytics and Facebook Pixel integration, auto-link keywords, affiliate disclosures, password protection, link scheduling & expiry, the broken link and full-site scanners, custom domains, role management, email reporting and the AI Bulk Link Generator.

[Get BetterLinks PRO →](https://betterlinks.io/#pricing)

## 💜 More From WPDeveloper

🔝 [Essential Addons For Elementor](https://wordpress.org/plugins/essential-addons-for-elementor-lite/) – Elementor extensions library with 2 million+ active installs.

👉 [Essential Blocks For Gutenberg](https://wordpress.org/plugins/essential-blocks/) – Advanced block library to supercharge the WordPress editor.

🔔 [NotificationX](https://wordpress.org/plugins/notificationx/) – Social proof & FOMO marketing solution that increases conversion rates.

📄 [EmbedPress](https://wordpress.org/plugins/embedpress/) – Embed content from 250+ sources with one click, in Gutenberg and Elementor.

📝 [BetterDocs](https://wordpress.org/plugins/betterdocs) – Documentation & knowledge base solution that reduces your support load.

⏰ [SchedulePress](https://wordpress.org/plugins/wp-scheduled-posts/) – Complete WordPress scheduling with an editorial calendar and social sharing.

☁️ [Templately](https://wordpress.org/plugins/templately/) – 5000+ ready templates for Elementor & Gutenberg with cloud collaboration.

🎨 [Flexia](https://wordpress.org/themes/flexia/) – Lightweight, customizable, multipurpose WordPress theme.

## 👨‍💻 Documentation & Support

* [Documentation & tutorials](https://betterlinks.io/docs/)
* [YouTube playlist](https://www.youtube.com/watch?v=ZJqBrFhQC1A&list=PLWHp1xKHCfxBtIjolI693SDWtdfKZCc37)
* [Community support forum](https://wordpress.org/support/plugin/betterlinks/)
* [Facebook community](https://www.facebook.com/groups/wpdeveloper.net/)
* Loving BetterLinks? [Leave a ⭐⭐⭐⭐⭐ review](https://wordpress.org/support/plugin/betterlinks/reviews/?rate=5#new-post)

== External services ==

BetterLinks connects to the services below only in the cases described.

= YouTube (youtube-nocookie.com) =

The Quick Setup wizard embeds a getting-started video. When an administrator opens BetterLinks → Quick Setup, YouTube receives the viewer's IP address and browser details. [Terms](https://www.youtube.com/t/terms) · [Privacy](https://policies.google.com/privacy)

= WPDeveloper usage insights (send.wpinsight.com) =

Only if an administrator opts in. Sends site details, WordPress and PHP versions, installed plugins and theme, the admin email and BetterLinks usage stats, daily and on deactivation. Turn it off in BetterLinks → Settings → Tracking. [Terms](https://wpdeveloper.com/terms-and-conditions/) · [Privacy](https://wpdeveloper.com/privacy-policy/)

= ip-api.com =

Only with the usage insights above: the administrator's IP address is sent once, on opt-in, to look up the site's country. [Legal & privacy](https://ip-api.com/docs/legal)

= AI assistants you connect over MCP =

Off by default. A connected assistant receives only the link, category and analytics data returned by the tools it calls; the assistant provider's own terms and privacy policy apply.

== Installation ==

= From your WordPress dashboard =

1. Go to **Plugins → Add New**.
2. Search for **BetterLinks**.
3. Click **Install Now**, then **Activate**.
4. Follow the Quick Setup wizard, or head straight to **BetterLinks → Manage Links** to create your first short link.

= Manual installation =

1. Upload the `betterlinks` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Open **BetterLinks** in your admin sidebar to get started.

Full setup guide: [betterlinks.io/docs](https://betterlinks.io/docs/)

== Frequently Asked Questions ==

= What is BetterLinks used for? =

BetterLinks is a WordPress link management plugin for creating short links, branded links, affiliate links, redirects and trackable campaign URLs directly from your WordPress dashboard.

= Who should use BetterLinks? =

Affiliate marketers, bloggers, SEO professionals, agencies, content creators, ecommerce teams and any site owner who needs to shorten, cloak, organize, redirect and track links in WordPress.

= Is BetterLinks free? =

Yes. The free plugin includes unlimited short links, 301/302/307 redirects, categories and tags, click tracking and analytics, the UTM builder, CSV import/export and one-click migration from other plugins. BetterLinks PRO adds cloaking, dynamic redirects, advanced analytics and more.

= Do I need a third-party URL shortener like Bitly? =

No. BetterLinks creates short links on your own domain, so your branding stays intact and your click data stays in your own database — with no per-click pricing.

= Can I create branded short links? =

Yes. Every short link is served from your own site by default, and BetterLinks PRO adds full custom domain support if you want a separate short domain.

= Does BetterLinks track link clicks and analytics? =

Yes. BetterLinks records total and unique clicks and referrers, with a real-time dashboard and date-range filtering. Bot traffic is filtered out automatically. BetterLinks PRO adds country, browser and device reports.

= Can I use BetterLinks for affiliate link management? =

Yes — it is built for it. Shorten, cloak, organize and track affiliate URLs, add nofollow/sponsored attributes, automatically link keywords across your content, and display FTC-friendly affiliate disclosures.

= Will BetterLinks slow down my website? =

No. Redirects resolve from an optimized cache rather than repeated database queries, so performance stays consistent even with tens of thousands of links.

= Can I migrate from Pretty Links or ThirstyAffiliates? =

Yes. BetterLinks includes one-click migration for Pretty Links, ThirstyAffiliates and Simple 301 Redirects, plus CSV import. Keep the old plugin active until the migration finishes.

= Does BetterLinks include a UTM builder? =

Yes. Add UTM campaign parameters to any link. BetterLinks PRO adds saved UTM templates that you can apply across many links at once.

= Can BetterLinks find broken links on my site? =

Yes. BetterLinks PRO includes a broken link scanner for your short links and a full-site scanner that crawls your published content, both with scheduled scans and email reports.

= Does BetterLinks work with Gutenberg and Elementor? =

Yes. There is a Gutenberg block plus instant-redirect controls in both the block editor and Elementor, so you can create and insert short links while you write.

= Is BetterLinks translation ready? =

Yes. BetterLinks is fully translation-ready and compatible with WPML.

= What is the MCP connector? =

MCP (Model Context Protocol) is the open standard AI assistants use to work with outside tools. BetterLinks includes its own MCP server, so assistants like Claude, ChatGPT, Claude Code, Cursor and VS Code can create links, organise them and read your click analytics for you, in plain English.

= Do I need another plugin or a paid service to use MCP? =

No. The MCP server and the WordPress Abilities API runtime are bundled with BetterLinks and run on your own site. There is no companion plugin to install, no hosted broker in the middle, and no extra subscription.

= Is the MCP connector safe to turn on? =

It is off by default and nothing is exposed until you enable it in BetterLinks → MCP. Clients connect either through a one-time OAuth approval or a connection token, every request runs as the administrator who approved it and respects that account's permissions, and you can reset the token or revoke any connected app at any time.

= Which AI assistants can connect to BetterLinks? =

Any MCP-capable client. The setup screen has ready-made instructions for Claude and ChatGPT (via OAuth) and for Claude Code, Cursor and VS Code (via a copy-paste command). Other MCP clients can connect using the endpoint URL shown on the same screen.

== Screenshots ==

1. Link shortening & custom links — turn any long URL into a short, branded link
2. Advanced redirects — 301, 302, 307 and cloaked redirects with full control
3. Auto-Link Keywords — turn chosen keywords into links across your whole site
4. Link analytics — clicks, unique visitors, referrers and campaign performance
5. Full Site Link Scanner — find and fix broken links across your content
6. AI Bulk Link Generator — create short links for many posts at once

== Changelog ==

= 3.1.4 - 22/09/2026 =

- Improvement: Added compatibility alert and update notice
- Improvement: MCP connector now has more tools, asks for confirmation before destructive actions and uses a more secure connection
- Fixed: Redirect loops between short links
- Fixed: Instant Redirect not applying in the block editor after a refused save
- Security: Improved plugin security enhancements
- Few minor bug fixes & improvements

= 3.1.3 - 10/09/2026 =

- Fixed: MCP link tools now apply your link prefix and keep multi-segment slugs intact
- Few minor bug fixes & improvements

= 3.1.2 - 23/08/2026 =

- Few minor bug fixes & improvements

= 3.1.1 - 21/08/2026 =

- Security: Improved plugin security enhancements
- Few minor bug fixes & improvements

= 3.1.0 - 11/08/2026 =

- Added: MCP connector — connect Claude, ChatGPT, Claude Code, Cursor or VS Code and manage your links in plain English
- Added: Built-in MCP server with 14 tools covering links, categories, tags, click analytics and settings, powered by a bundled WordPress Abilities API runtime
- Added: New BetterLinks → MCP screen with OAuth and copy-paste setup, connected app management, one-click revoke and a live connection test
- Improvement: MCP access is off by default, scoped to the administrator who approves it, and revocable at any time
- Fixed: Creating a link could add a junk category when the default category setting held an invalid or deleted value
- Few minor bug fixes & improvements

= 3.0.1 - 10/08/2026 =

- Fixed: Bulk status changes reset a link's category to Uncategorized
- Fixed: Unique click counts were mismatched between links, or shown as 1
- Fixed: Link in Bio category showed no links after being enabled
- Fixed: Category and tag deletion reported success when nothing was deleted
- Fixed: Renaming a category or tag reported a failure even when it saved
- Fixed: Quick Setup stayed in the menu on sites that never ran the wizard
- Improvement: Compact list view now fits more than twice as many links on screen
- Improvement: Restricted the country lookup endpoint to authorised users
- Few minor bug fixes & improvements

= 3.0.0 - 03/08/2026 =

- Added: All-new interface with a refreshed, optimized design
- Improvement: Streamlined workflows with better performance and accessibility
- Few minor bug fixes & improvements

= 2.4.13 - 23/06/2026 =

- Few minor bug fixes & improvements

= 2.4.12 - 07/06/2026 =

- Improvement: Redesigned Import/Export with improved field mapping and support for complex imports.
- Improvement: Resolved Plugin Check (PCP) warnings and errors.
- Few minor bug fixes & improvements

= 2.4.11 - 21/05/2026 =

- Few minor bug fixes & improvements

= 2.4.10 - 11/05/2026 =

- Improvement: WPML support for Affiliate Link Disclosure Notice translations.
- Fixed: Missing ABSPATH protection issue causing PHP fatal errors on direct file access.
- Fixed: CSV import issue stripping `%` characters from `target_url`, causing broken redirects.
- Few minor bug fixes & improvements

= 2.4.9 - 16/04/2026 =

- Improvement: Added WPML-compatible translatable string support.
- Few minor bug fixes & improvements

= 2.4.8 - 06/04/2026 =

- Fixed: Resolved an issue where duplicating a BetterLinks URL caused the original Shortened URL to stop working
- Few minor bug fixes & improvements

= 2.4.7 - 26/02/2026 =

- Improvement: Improved Analytics table with dynamic column resize control
- Improvement: Added toast notifications for all status updates
- Few minor bug fixes & improvements 

= 2.4.5 - 10/02/2026 =

- Improvement: Added proper handling for root relative target URLs beginning with a forward slash ("/")
- Fixed: Link management issue Gutenberg editor 
- Few minor bug fixes & improvements

= 2.4.4 - 02/02/2026 =

- Fixed: Missing Prefix issue in default general settings
- Fixed: Duplicate short link data related issue
- Few minor bug fixes & improvements 

= 2.4.3 - 14/01/2026 =

- Fixed: Migration error caused by a scalar value being treated as an array.
- Few minor bug fixes & improvements 

= 2.4.2 - 05/01/2026 =

- Fixed: Share Task issue with Fluent Boards Link Management
- Few minor bug fixes & improvements

= 2.4.1 - 28/12/2025 =

- Fixed: Missing geolocation script reference causing console errors
- Fixed: Database migration issue during plugin updates
- Few minor bug fixes & improvements

= 2.4.0 - 18/12/2025 =

- Improvement: Introduced Bulk Deletion option in Specific Clicks Analytics and Item-wise Analytics.
- Improvement: Introduced Multiple URL Slug Generation mechanism
- Few minor bug fixes & improvements

= 2.3.4 - 27/11/2025 =

- Few minor bug fixes & improvements


= 2.3.3 - 18/11/2025 =

- Few minor bug fixes & improvements

= 2.3.2 - 26/10/2025 =

- Improvement: Added “Refresh Stats” option on the Analytics page for Instant data updates.
- Improvement: Enhanced the “Reset” mechanism — it now supports date-wise resets with individual link data clearing.
- Few minor bug fixes & improvements

= 2.3.1 - 21/09/2025 =
- Improvement: Added Default Category selection option.
- Improvement: Database repair now runs automatically during installation.
- Few minor bug fixes & improvement

= 2.3.0 - 26/08/2025 =

- Added: Support for Category Management
- Few minor bug fixes & improvements

= 2.2.2 - 19/12/2024 =

- Few minor bug fixes & improvements

= 2.2.1 - 27/11/2024 =

- Fixed: Fatal Error on Plugin activation 
- Fixed: Quick Setup default configuration value
- Fixed: Prevent unwanted duplicate link creation with Quick Link Creation Feature
- Few minor bug fixes & improvements

= 2.2.0 - 26/11/2024 =

- Improvements: Added 'Migrate from Database' option for 3rd party plugins in Tools
- Improvements: Added Link Duplication in Manage Links
- Improvements: Asset loading performance in Gutenberg
- Few minor bug fixes & improvements

= 2.1.12 - 21/11/2024 =

- Improvements: Added Total Click & Unique Click count in Graph Analytics
- Fixed: Analytics filter query for total click & unique click count
- Few minor bug fixes & improvements

= 2.1.11 - 14/11/2024 =

- Few minor bug fixes & improvements

= 2.1.10 - 10/11/2024 =

- Added: Quick Setup Wizard
- Fixed: Referer not showing in Analytics when using 'Open in New Tab' in gutenberg
- Tested up to WordPress version 6.7
- Few minor bug fixes & improvements

= 2.1.9 - 23/10/2024 =

- Fixed: Double link creation issue when using Instant Redirect Feature
- Fixed: Unicode character encoding issue with Quick Link Creation Feature
- Few minor bug fixes & improvements

= 2.1.8 - 15/10/2024 =

- Improved: Security Enhancement
- Few minor bug fixes & improvements

= 2.1.7 - 06/09/2024 =

- Few minor bug fixes & improvements

= 2.1.6 - 05/09/2024 =

- Improvements: Added 7G Firewall compatibility
- Fixed: Quick Link Creation extra forward slash ("/") issue
- Fixed: Quick Link Creation Empty title issue
- Few minor bug fixes & improvements

= 2.1.5 - 11/08/2024 =

- Fixed: Resolved issue with undefined short slugs in Fluent Boards Link Management.
- Few minor bug fixes & improvements

= 2.1.4 - 16/07/2024 =

- Fixed: PHP Fatal Error Issue.
- Few minor bug fixes & improvement

= 2.1.3 - 16/07/2024 =

- Added: WordPress 6.6 Compatibility
- Few minor bug fixes & improvement

= 2.1.2 - 07/07/2024 =

- Fixed: Uncaught Error for using a scalar value as an array
- Few minor bug fixes & improvement

= 2.1.1 - 26/06/2024 =

- Improvement: Added Fluent Boards Shortened Link Deletion on Task Delete or Archive
- Improvement: Added Category Hide option in Fluent Boards Links Management settings
- Improvement: Added support for UTF-8 encoded characters in Quick Link Creation Feature
- Fixed: Fatal error - Undefined variable in Quick Link Creation Feature
- Fixed: Empty Sample CSV file on export
- Few minor bug fixes & improvement

= 2.1.0 - 11/06/2024 =

- Added: Fluent Boards Link Management Feature
- Few minor bug fixes & improvement

= 2.0.0 - 19/05/2024 =

- Added: Quick Link Creation Feature
- Improvement: Link title will be automatically generated from the target URL's title
- Improvement: Manage Links date format now matches the WordPress default format
- Improvement: Manage Tags UI
- Fixed: Analytics now displays from the top of the page
- Fixed: Added proper validation for Custom Fields
- Few minor bug fixes & improvement

= 1.9.2 - 13/05/2024 =

- Fixed: HTML element tag support inside Link Title
- Few minor bug fixes & improvement

= 1.9.1 - 24/04/2024 =

- Fixed: Exported CSV file's column label
- Few minor bug fixes & improvement


[See changelog for all versions](https://betterlinks.io/changelog/).

== Upgrade Notice ==

= 3.1.4 =
Update BetterLinks and BetterLinks Pro together: Pro features in this release need BetterLinks Pro 3.0.4 or later. Your links, analytics and settings are kept.

= 3.1.0 =
Adds the BetterLinks MCP connector, so Claude, ChatGPT, Cursor and other AI assistants can manage your links for you. It is off by default — enable it under BetterLinks → MCP. Also fixes a junk category being created when the default category setting held an invalid value.
