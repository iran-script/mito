# Contacts

Contacts are private `user_contacts` rows owned by the adding user. They do not expose Telegram identities and do not notify the other person. Add/remove operations reject self and either direction of blocking; duplicate rows are prevented by a database unique constraint.
