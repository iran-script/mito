# Economy administration

Seed prices are 1 coin for direct_message, 2 for chat_acceptance and 4 for telegram_id_share. Product prices, package availability and signup_bonus live in the database. Purchase orders retain their existing amount/currency/coin snapshots; changing configuration does not rewrite orders or transactions. Games and discovery remain free; chat-request creation is free and normal chat acceptance keeps its configured paid rule.

The protected Filament finance resources use `FinanceService`, which checks fresh admin authorization and a mandatory reason before invoking the existing `WalletService` / `EconomyAdminService`. Moderators cannot change economy values. Feature pricing changes apply to subsequent paid actions. Explicitly disabled features reject actions; they do not become free.

Coin packages support name, base/bonus coins, integer price, three-letter currency, active/featured state, sort order and optional availability window. Service validation rejects negative or malformed values. Editing affects future purchases only. Purchase orders and provider references are read only; there is no arbitrary Mark Paid action or new payment-provider integration.

Wallets have explicit Credit Coins, Debit Coins and Refund actions. There is no balance editor. Credits/debits require positive whole coins, reason and an operation key; repeating the same operation key is idempotent, while changing its amount/user is rejected. Wallet row locking and existing insufficient-funds checks prevent negative balances. Refund locks the original debit and creates one compensating credit; credits cannot be refunded. The original entry is unchanged.

PostgreSQL UPDATE/DELETE triggers protect all coin transactions and admin audit rows. Audit and movement commit together. Historical records cannot be edited through model/query builder or panel. Database schema owners remain trusted operators.

Signup bonus remains a once-only registration-completion grant using the existing idempotent ledger reference. Changing it does not grant retroactive coins. The economy settings page allowlists signup_bonus; Gold settings use their own allowlisted keys. No generic arbitrary settings editor is exposed.

Repeat seed runs insert missing defaults without overwriting existing admin-managed pricing, settings, packages or question content.

## Persian presentation

Persian is the default product language. Internal codes and domain rules remain unchanged. See [localization policy](LOCALIZATION.md) for RTL, local Vazirmatn font, translation architecture, Tehran display time, Gregorian dates and numeric conventions.
