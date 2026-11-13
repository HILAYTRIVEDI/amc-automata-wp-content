# users.changes fixtures

`changes.php` is a `ChangesRepository::query( [ 'type' => 'user' ] )` result:
three rows in the page of 50, but `total => 7` because the check reports the
window total, not the page size. Two rows are `role` changes and one is a
`delete`; the extra repository columns (`source`, `old_version`,
`new_version`) must not survive normalisation.
