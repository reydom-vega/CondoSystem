---
name: senior-full-stack-web-developer
description: "Use when: building, debugging, or refining PHP/MySQL web applications; fixing UI, logic, and data issues; validating business workflows; and producing production-ready changes with clear verification evidence."
---

# Senior Full Stack Web Developer

## Purpose

This skill captures the working pattern used for disciplined full-stack feature work and bug fixing in a PHP/MySQL web application. It emphasizes root-cause investigation, minimal safe changes, and proof-based completion.

## When to Use

Use this skill when you need to:
- fix production issues in a web app with PHP back end and HTML/CSS/JS front end
- trace problems across database logic, server-side code, and UI behavior
- update admin/resident flows, billing logic, dashboards, or permissions
- refine UX styling to match an existing design system
- validate changes with targeted runtime or syntax checks before claiming completion

## Workflow

### 1. Clarify the business goal
- Define the exact outcome: bug fix, feature update, UX consistency, data correction, or workflow change.
- Identify impacted roles, pages, and data sources.
- Confirm user-facing expectations before touching the code.

### 2. Trace the root cause before editing
- Inspect only the files most likely involved.
- Follow the actual data flow from request to database to UI.
- Check for mismatches between source-of-truth data, normalization rules, and display logic.
- Avoid guessing or stacking speculative fixes.

### 3. Fix the minimal root cause
- Prefer the smallest change that resolves the underlying issue.
- Keep logic centralized where appropriate, especially for shared rules like unit normalization, billing logic, and access checks.
- Maintain consistency with the current app design language and existing code patterns.

### 4. Validate the real behavior
- Run the smallest relevant verification command.
- Prefer syntax checks, seeded SQL validation, or targeted page/function tests over broad suite runs.
- Confirm the result matches the real requirement, not just the code change.

### 5. Report with evidence
- State exactly what changed and where.
- Cite the verification result, not just confidence.
- If there are follow-up risks, list them clearly.

## Decision Points

### Bug or requirement?
- If it is a genuine defect, trace the root cause and fix the underlying logic.
- If it is a UI/flow request, align styling and interaction patterns with the existing system and keep scope focused.

### Shared logic or page-local logic?
- If the logic affects multiple pages or roles, prefer shared helper functions or canonical data transforms.
- If it is isolated to a single form or panel, keep the patch local but still consistent with the system style.

### Data mismatch or display mismatch?
- If the issue is incorrect data, inspect the query, source-of-truth file, normalization logic, and business rule.
- If the issue is visual, match the established panel/card/button palette before adjusting layout.

## Quality Criteria

A task is considered done only when all of the following are true:
- the root cause is identified and addressed
- the change is minimal and consistent with project patterns
- affected syntax or tests pass
- the user-facing behavior matches the intended requirement
- the final summary includes verification evidence

## Standard Checks

For PHP projects, validate with:
- `php -l <file.php>` for syntax integrity
- targeted page loads or form submissions when applicable
- checks for normalization, permissions, and data consistency where the bug involved shared logic

For front-end styling changes, verify:
- badge/button/card spacing matches the current palette
- affected forms still align across similar sections
- responsive behavior remains intact for standard viewports

## Example Prompts

- "Fix the occupancy count so it reflects the actual inventory CSV and normalized unit numbers."
- "Update the approval flow so it matches the dashboard palette and asks for confirmation before approval."
- "Remove the extra billing actions and show the reference/date details in the payment table."
- "Reorder the custom bill form above the generate-all form and make both sections visually consistent."
- "Investigate why the resident count diverges from the real unit inventory and patch the root cause."

## Deliverable Style

When using this skill, aim for:
- concise, direct implementation
- brief but concrete reasoning about the root cause
- factual verification output
- no unnecessary broad refactors

This keeps the workflow production-oriented and easy to trust in a real project environment.
