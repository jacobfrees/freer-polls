# Contributing to Freer Polls

Thanks for considering contributing. Freer Polls is free, open-source software, and your help keeps it that way.

## Code of Conduct

Please read [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md). By participating you agree to follow it.

## How to contribute

- **Report a bug** or request a feature by opening an Issue.
- **Pick a `good-first-issue`** for an easy starting point.
- **Send a Pull Request**: fork the repo, create a branch, make your change, open the PR.

## Development setup

1. Install Freer Polls into a WordPress test site (WordPress 5.8+, PHP 7.4+).
2. For local development, clone this repo and symlink or copy it into `wp-content/plugins/freer-...`.
3. Make your changes; the code is prefixed `freer_...` throughout, keep new code on the same convention.

## Testing

- Run `php -l` on every changed PHP file. All must report `No syntax errors detected`.
- Test on a clean install and, if applicable, in both public and admin contexts.
- Keep changes backward-friendly where you can.

## Developer Certificate of Origin (DCO)

This project uses the Developer Certificate of Origin. Every commit must be signed off:

```
git commit -s -m "Describe the change"
```

By signing off you certify that you wrote the change (or have the right to contribute it), and you agree it is licensed under this project's license (GPL-2.0-or-later).

## License

Freer Polls is licensed under **GPL-2.0-or-later**. By contributing you agree your contributions are licensed under the same license. See [LICENSE](LICENSE).
