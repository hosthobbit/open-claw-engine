# GitHub Actions

This folder connects the repo to **GitHub Actions**. Once you push this code to a GitHub repository:

- **CI** (`.github/workflows/ci.yml`) runs on every push and pull request to `main` or `master`.
- It checks PHP syntax on all `.php` files and optionally runs PHP_CodeSniffer (WordPress coding standards) if Composer deps are installed.

## Connecting this project to GitHub

1. Create a new repository on GitHub (e.g. `open-claw-engine`).
2. In your project folder, run:
   ```bash
   git init
   git add .
   git commit -m "Initial commit: Open Claw Engine"
   git remote add origin https://github.com/YOUR_USERNAME/open-claw-engine.git
   git branch -M main
   git push -u origin main
   ```
3. Open the **Actions** tab on GitHub; the first workflow run will start automatically.

No secrets or tokens are required for the CI workflow; it only needs the code to be pushed.
