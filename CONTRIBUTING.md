# Contributing to ArtisanPack UI

As an open source project, ArtisanPack UI is open to contributions from everyone. You don't need to be a developer to contribute. Whether it's contributing code, writing documentation, testing the CMS or anything in between there's a place for you here to contribute.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Ways to Contribute](#ways-to-contribute)
- [Getting Started](#getting-started)
- [Development Commands](#development-commands)
- [Issue Templates](#issue-templates)
- [Branching Strategy](#branching-strategy)
- [Pull Request Process](#pull-request-process)
- [Labels and Milestones](#labels-and-milestones)
- [Forking and Contributing](#forking-and-contributing)
- [Naming Conventions](#naming-conventions)

## Code of Conduct

In order to make this a best place for everyone to contribute, there are some hard and fast rules that everyone needs to abide by.

* ArtisanPack UI is open to everyone no matter your race, ethnicity, gender, who you love, etc. In order to keep it that way, there's zero tolerance for any racist, misogynistic, xenophobic, bigoted, Zionist, antisemitic (yes, there is a difference), Islamophobic, etc. messages. This includes messages sent to a fellow contributor outside of this repository. In short, don't be a jerk. Failure to comply will result in a ban from the project.
* Be respectful when communicating with fellow contributors.
* Respect the decisions made for what to include in the CMS.
* Work together to create the best possible content management system.

## Ways to Contribute

There are a ton of different ways to contribute to ArtisanPack UI even if you're not a developer. Here are some (but not all) of the ways you can contribute to the project:

* Write code for ArtisanPack UI core
* Create plugins to extend ArtisanPack UI
* Create themes to add designs for ArtisanPack UI
* Test and report bugs found in the CMS
* Write documentation
* Write tutorials and talk about ArtisanPack UI on your blog and/or social media profiles
* Review pull requests
* Improve existing code
* Help answer questions in issues

## Getting Started

### Prerequisites

Before contributing, make sure you have:
- Git installed on your machine
- PHP 8.3 or higher, with the `intl` and `openssl` extensions
- Composer
- A GitHub account

### Setting Up Your Development Environment

1. Fork the repository on GitHub (see [Forking and Contributing](#forking-and-contributing))
2. Clone your fork locally
3. Install dependencies: `composer install`

   `composer install` resolves the engine (`artisanpack-ui/ecommerce`) from Packagist. To work against a local engine checkout instead, add a path repository on your machine only, and don't commit it:

   ```bash
   composer config repositories.ecommerce '{"type": "path", "url": "../ecommerce", "options": {"symlink": true}}'
   composer update artisanpack-ui/ecommerce
   # Before committing, switch back to the Packagist engine CI uses:
   composer config --unset repositories.ecommerce
   composer update artisanpack-ui/ecommerce
   ```
4. Create a branch from the current release branch (see [Branching Strategy](#branching-strategy))
5. Make your changes
6. Run the tests and the linters (see [Development Commands](#development-commands))
7. Push to your fork
8. Open a pull request

## Development Commands

| Command | What it does |
|---|---|
| `composer test` | Runs the Unit and Feature suites (`./vendor/bin/pest --testsuite=Unit,Feature`) |
| `composer lint` | Checks code style: PHP-CS-Fixer in dry-run mode, then PHPCS |
| `composer fix` | Fixes code style with PHP-CS-Fixer |
| `composer cs` | Runs PHPCS only |
| `composer cs:fix` | Fixes what PHPCS can with PHPCBF |
| `vendor/bin/testbench ecommerce:lint:translations --no-engine --path="$PWD/src" --path="$PWD/resources" --lang="$PWD/lang"` | Fails on user-facing strings that aren't translatable, or keys missing from a shipped catalogue |

The code uses WordPress-style spacing (`foo( $bar )`, `[ 'key' => $value ]`). **Don't run Laravel Pint on this package.** Pint removes that spacing and reformats every file. Use `composer fix` instead. If Pint was run by mistake, delete `.php-cs-fixer.cache` and run `composer fix` to restore the spacing.

CI runs the same checks on every pull request: the linters, the translation lint, the PCI column lint, and the test suite on PHP 8.3 and 8.4 with Laravel 12 and 13 (plus a run on the lowest allowed dependencies). Make sure they pass locally first.

## Issue Templates

When creating an issue, you'll be prompted to choose a template. We have several templates to help you provide the right information:

### Bug Report Template

Use this template when you've found a bug. It will ask for:
- **Expected behavior** - What should happen
- **Current behavior** - What actually happens
- **Steps to reproduce** - How to recreate the bug
- **Environment** - Your OS, PHP version, Laravel version, package version
- **Possible solution** - If you have an idea
- **Additional context** - Screenshots, logs, anything else

The template applies the `bug` label.

### Feature Request Template

Use this when suggesting new functionality. It will ask for:
- **Problem statement** - What problem does this solve?
- **Proposed solution** - What would you like to happen?
- **Alternatives considered** - Other solutions you've thought about
- **Use cases** - How would this be used?

The template applies the `enhancement` label.

### Enhancement Template

Use this for improvements to existing features. It will ask for:
- **Current behavior** - How it works now
- **Proposed improvement** - How to make it better
- **Benefits** - Why this improvement is valuable
- **Proposed changes** - What would change
- **Backwards compatibility** - Will this break anything?

The template applies the `enhancement` label.

### Task Template

Use this for general tasks that don't fit other categories. It will ask for:
- **Task description** - What needs to be done
- **Acceptance criteria** - How we know it's complete
- **Context** - Why this is needed

The template applies the `task` label.

### Submitting Your Issue

After filling out the template:
1. Review your issue for completeness
2. Submit the issue
3. A maintainer will review and triage it

You can also open a blank issue for questions.

## Branching Strategy

### Main Branches

- **`main`** - The latest stable release
  - Every release is tagged on `main` (`vX.Y.Z`)
  - Protected: no direct pushes
- **`release/X.Y`** - The next release in progress (for example, `release/1.0` or `release/1.1`)
  - Pull requests for that release target this branch
  - CI runs on every push and pull request

### How a Release Flows

1. Work for a release happens on short-lived branches cut from `release/X.Y`.
2. Each change is merged into `release/X.Y` through a pull request.
3. When the release is ready, a maintainer opens a release pull request from `release/X.Y` into `main`, using the release template. Documentation and the changelog are updated in that pull request.
4. After it merges, the maintainer pushes the `vX.Y.Z` tag on `main`. The release workflow runs the tests and publishes the GitHub release from `CHANGELOG.md`.

### Your Branch

**Format:** `feature/short-description` or `fix/short-description`

**Examples:**
- `feature/add-dark-mode`
- `fix/navigation-bug`
- `feature/issue-123-user-profiles`

```bash
git fetch upstream
git checkout -b fix/your-bugfix upstream/release/1.1
```

Use the release branch that matches the issue's milestone. If you're not sure which one, ask in the issue.

### Workflow

1. **Create branch** from the current `release/X.Y` branch
2. **Make changes** and commit
3. **Push** to your fork
4. **Open a pull request** into that `release/X.Y` branch
5. **Wait for review** from a maintainer
6. **Address feedback** if needed
7. **Maintainer merges** when approved

**Important:** Don't target `main` with a feature or fix. `main` only receives release pull requests.

## Pull Request Process

### Before Opening a Pull Request

1. **Ensure there isn't an existing pull request** for the same change
2. **Link an open issue** - This project only accepts pull requests related to open issues
3. **Run the tests** - `composer test`
4. **Run the linters** - `composer lint` and the translation lint above
5. **Update documentation** in the code (PHPDoc) as needed. Pages under `docs/` are updated in the release pull request.

### Pull Request Templates

#### Default Template (Bug Fixes, Features, Enhancements, Tasks)

Use this for most pull requests. It includes:
- Description of changes, with `Closes: #123`
- Type of change (Bug fix, Feature, Enhancement, etc.)
- Testing performed
- **Accessibility tests** (required for all UI changes)
- Tests added
- Documentation updates
- Pre-submission checklist

#### Release Template (Maintainers Only)

This template is for `release/X.Y` → `main` pull requests and should only be used by maintainers.

### Pull Request Guidelines

**For External Contributors:**
1. Open your pull request using the Default template
2. Fill out all sections completely
3. Link to the related issue: `Closes #123`
4. Wait for maintainer review
5. Address any feedback promptly
6. A maintainer will approve and merge your pull request

**Note:** All pull requests require maintainer approval. External contributors cannot merge their own pull requests.

### Code Review Process

When you open a pull request:
1. A maintainer will review within 1-3 days
2. They may request changes or ask questions
3. Address feedback by pushing new commits
4. Once approved, the maintainer will merge

### After Your Pull Request Is Merged

- Your changes will be included in the next release
- The related issue will close when the change is released
- You'll be credited in the release notes
- Thank you for contributing! 🎉

## Labels and Milestones

### Labels

- `bug` - Something isn't working
- `enhancement` - New functionality or an improvement to existing functionality
- `task` - General development work

The issue templates apply these automatically. Maintainers add other labels during triage.

### Milestones

Milestones track releases (`v1.0`, `v1.1`, and so on). A milestone is assigned automatically when an issue is opened, and maintainers move issues between milestones when they schedule work. **You don't need to assign milestones.**

## Forking and Contributing

The repository is hosted on GitHub at [ArtisanPack-UI/ecommerce-paypal](https://github.com/ArtisanPack-UI/ecommerce-paypal).

1. **Fork the repository**
   - Go to the project page
   - Click "Fork"

2. **Clone your fork**
   ```bash
   git clone git@github.com:your-username/ecommerce-paypal.git
   cd ecommerce-paypal
   ```

3. **Add the upstream remote**
   ```bash
   git remote add upstream git@github.com:ArtisanPack-UI/ecommerce-paypal.git
   git fetch upstream
   ```

4. **Create a branch from the release branch**
   ```bash
   git checkout -b feature/your-feature upstream/release/1.1
   ```

5. **Make changes and push**
   ```bash
   git add .
   git commit -m "feat: add your feature"
   git push origin feature/your-feature
   ```

6. **Open a pull request**
   - Go to your fork on GitHub
   - Click "Compare & pull request"
   - Set the base repository to `ArtisanPack-UI/ecommerce-paypal` and the base branch to the release branch you started from
   - Fill out the pull request template
   - Submit

### Keeping Your Branch Updated

```bash
git fetch upstream
git rebase upstream/release/1.1
git push --force-with-lease origin feature/your-feature
```

## Naming Conventions

To keep things consistent across the code base, it's important to follow these naming conventions:

### PHP Code

- **Class names**: Pascal Case - `ClassName`
- **Function names**: Camel Case - `functionName`
- **Variables**: Camel Case - `variableName`
- **Array keys**: Camel Case - `$array['arrayKey']`
- **Database columns**: Snake case - `table_column`
- **Constants**: Upper snake case - `CONSTANT_NAME`

### Files and Directories

- **PHP class files**: Match class name - `ClassName.php`
- **Config files**: Kebab case - `config-name.php`
- **View files**: Kebab case - `view-name.blade.php`

### Git Branches

- **Feature branches**: `feature/short-description`
- **Bug fix branches**: `fix/short-description`
- **Use hyphens** not underscores
- **Keep it short** but descriptive
- **Examples**: `feature/dark-mode`, `fix/navbar-responsive`

### Commit Messages

Follow conventional commit format:

```
type: Short description

Longer description if needed.

Closes #123
```

**Types:**
- `feat:` - New feature
- `fix:` - Bug fix
- `docs:` - Documentation changes
- `style:` - Code style changes (formatting)
- `refactor:` - Code refactoring
- `test:` - Test updates
- `chore:` - Maintenance tasks

**Examples:**
```
feat: Add dark mode support

Implements dark mode theme with toggle in settings.
Includes proper color contrast for accessibility.

Closes #456
```

```
fix: Resolve navigation menu overlap on mobile

Menu was overlapping content on screens < 768px.
Updated CSS media queries and z-index values.

Closes #789
```

## Questions?

If you have questions about contributing:

1. **Check existing documentation** - The `docs/` folder, README, this guide
2. **Search existing issues** - Your question might be answered
3. **Ask in an issue** - Open a blank issue with your question
4. **Join discussions** - Comment on relevant issues

## Thank You!

Thank you for contributing to ArtisanPack UI! Your contributions help make this project better for everyone.

Every contribution matters, whether it's:
- 🐛 Fixing a typo in documentation
- ✨ Adding a major feature
- 🧪 Writing tests
- 📝 Improving documentation
- 💡 Suggesting ideas

We appreciate your time and effort! 🎉

---

**Project Maintainer:** Jacob Martella ([ArtisanPack UI on GitHub](https://github.com/ArtisanPack-UI))  
**License:** [MIT](LICENSE)
**Website:** [https://jacobmartella.me](https://jacobmartella.me)
