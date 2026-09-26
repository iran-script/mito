# Persian localization

Persian (`fa`) is the application default; English is the fallback. Telegram messages and button captions use Laravel's `lang/fa.json`. Callback payloads, enum values, database identifiers, financial feature codes and game codes remain English and unchanged. User-authored names, messages and event/question content are not machine-translated.

`App\Support\Presentation` centralizes statuses, ranks, categories, seeded catalog labels, dates and safe errors using `lang/fa/presentation.php`. Authentication and validation use `lang/fa/auth.php` and `validation.php`. Filament's Persian translations are supplemented by application-owned overrides under `lang/vendor`; vendor files are not modified. `LocalizationServiceProvider` supplies shared field/filter/column presentation.

## Admin layout and font

All 17 operational resources, three settings pages, the dashboard and login use Persian labels. Filament renders `lang="fa" dir="rtl"`; its RTL layout is supplemented by local logical-alignment CSS. Email, password, URL, number and date-time inputs remain left-to-right. Browser review covers login, right-hand responsive sidebar, tables, filters and action dialogs; HTTP tests cover Persian rendering and real session login/logout.

Vazirmatn v33.003 is served locally from `public/fonts/vazirmatn`, with its SIL Open Font License. Source: https://github.com/rastikerdar/vazirmatn/tree/v33.003 . WOFF2 SHA-256: `4e3fa217d38fdafc1fea4414ceb58ca5e662cf0ab5fa735a8c8c20e8b42cad92`. No font CDN or runtime font download is required.

## Dates, numbers and compatibility

Display uses Gregorian `Y/m/d H:i` dates in Asia/Tehran. Telegram date captions identify the Gregorian calendar. Database timestamps and application/game daily and weekly boundaries remain UTC, preserving existing reward and ranking semantics. Persian event-wizard date input is interpreted in Asia/Tehran and converted to UTC. No Jalali dependency was added.

Display uses ASCII digits consistently; numeric values remain numeric in storage. Persian/Arabic numeral input is normalized at supported numeric boundaries (number guesses, event capacity and event dates). Technical commands such as `/start`, date format examples, email addresses and user-authored text may remain Latin.

The original regression suite explicitly uses English fallback copy contracts; dedicated Persian tests exercise actual Telegram handlers and all admin resource/settings screens. Real HTTP login tests run the application in Persian, retaining the dedicated `admin` guard / `admins` provider and active-admin checks.

Existing deployments should set `APP_LOCALE=fa` and rebuild configuration cache (`php artisan config:cache`) after deployment. Queue workers must be restarted to load updated translations. No schema changes or payment integration are part of localization.
