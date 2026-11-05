# Contributing

Thanks for helping improve digicyfr-image-alt-use-audit. The project is licensed under GPL-2.0-or-later; contributions are made under that license.

1. Open a GitHub issue for a bug or feature proposal, with WordPress/PHP versions and a small reproducible example. Use the private channel in [SECURITY.md](SECURITY.md) for vulnerabilities.
2. Fork the repository and open a pull request against `main`. Keep each change focused and document any user-visible behavior or data-lifecycle change.
3. Run `find . -type f -name '*.php' -not -path './dist/*' -print0 | xargs -0 -r -n1 php -l`, `find assets -type f -name '*.js' -print0 | xargs -0 -r -n1 node --check`, and the build and verification commands in [README.md](README.md).
4. Add a meaningful WordPress integration case when changing scanner, storage, authorization, export, or uninstall behavior. Do not include customer data, secrets, production URLs, or generated ZIPs in a pull request.

The repository's `release-files.txt` is the package allowlist. Review any change to that file carefully. The `release/0.1.0` branch records the published source snapshot; normal changes go to `main`.
