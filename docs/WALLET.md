# Wallet and coin ledger

Each user has one `wallets` row. `wallets.balance` is a transactionally maintained cache; the immutable `coin_transactions` ledger is the financial source of truth. Amounts are signed: positive credits, negative debits. Historical rows are never edited or deleted; refunds and corrections are compensating entries linked by `reverses_transaction_id`.

`WalletService` locks the wallet row in PostgreSQL before checking balance and writing both the ledger row and cached balance. Idempotency keys make webhook retries and payment callbacks safe. A debit that would make the balance negative raises `InsufficientCoinsException` and rolls back its enclosing business transaction.

Direct messages charge the sender and accepted chat requests charge the requester. The `PaidActionGate` resolves current prices from `coin_feature_prices`; the initial free compatibility path only applies to legacy records without a wallet. Telegram identity sharing charges only after both usernames are available and the recipient accepts.

Purchase orders snapshot package quantities and minor-unit price. Fulfillment credits base plus bonus once, under an order lock. The current project has no external payment gateway; package creation and fulfillment services are ready for a provider callback.
