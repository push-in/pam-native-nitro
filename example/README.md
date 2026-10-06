# Offline notes demo

A one-screen PAM Native app for `pushinbr/pam-native-nitro`: a typed `Note`
model with an int-backed enum, one `Nitro::boot()` + `Nitro::prepare()`,
bounded queries, `save()`/`delete()`, an atomic `Nitro::batch()` and a scoped
`Nitro::replaceMany()` that simulates a server snapshot.

```bash
cd example
pam composer install
pam doctor --fix
pam dev            # or: pam build
```

The app installs the released package from Packagist. Notes survive app
restarts; add a property with a default to `Note` and relaunch to see the
additive schema evolution keep every row.
