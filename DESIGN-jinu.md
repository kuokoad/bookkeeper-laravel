# Jinu Accounts — Design System

Source reviewed: 23 October 2026. Scope: the Next.js reference application in `C:\ChatGPT\clearledger`.

This document describes the implemented visual language and the rules for maintaining it. Product scope and accounting requirements remain authoritative in [PROJECT_PLAN.md](../accounting-project-planning/PROJECT_PLAN.md); release status and approved decisions remain in [PROGRESS.md](../accounting-project-planning/PROGRESS.md). The separate Laravel application has its own implementation and later capabilities. This document does not authorize changes to either application.

## Design direction

Jinu Accounts is a practical accounting workspace for accountants, bookkeepers and business owners. Its interface should feel calm, precise and familiar: pale green surroundings, white working surfaces, dark readable text and restrained green accents.

- Keep company, currency, financial year and report dates visible where relevant.
- Prioritize financial figures, readable tables and source drill-down over decoration.
- Distinguish saving a draft from posting an accounting transaction.
- Show unavailable history, unresolved classifications and validation failures explicitly.
- Use real authorized accounting data for every financial widget. Never insert illustrative balances into the working interface.
- Keep the product accounting-only; inventory is outside scope.

## Brand

The product name is **Jinu Accounts**. The `clearledger` directory and database identifiers are retained for compatibility. Application metadata uses “Clear books. Confident decisions.”

Reuse [Brand](src/components/brand.tsx) for the folded green J symbol and wordmark. The mark is an inline SVG recreation of the approved reference image. Its three fills are `#2d8c53`, `#8abd69` and `#55a75e`.

| Element | Current treatment |
|---|---|
| Symbol | 42 × 44px rendered SVG |
| Jinu wordmark | 37px, weight 750, −1.5px letter spacing |
| ACCOUNTS tagline | 7px, 4px letter spacing |
| Default wordmark color | `#124d43` |
| Login variant | White wordmark with pale green tagline |

Preserve the logo proportions and its typography. The approved 28% application text enlargement deliberately excluded the wordmark and tagline.

## Styling sources and tokens

[RootLayout](src/app/layout.tsx) imports [globals.css](src/app/globals.css) first, then [jinu.css](src/app/jinu.css). The first stylesheet supplies shared forms, reports, document layouts and base behavior; the second supplies the Jinu theme, shell, dashboard and custom selects. Read both when changing shared presentation: later declarations, specificity and media queries determine the result.

The effective root tokens are:

| Token | Value | Purpose |
|---|---|---|
| `--ink` | `#172532` | Main text and headings |
| `--muted` | `#748691` | Supporting text |
| `--green` | `#13784b` | Primary actions and accents |
| `--green-dark` | `#10663f` | Darker primary variant |
| `--canvas` | `#f1f6f3` | Page background |
| `--border` | `#e1e9e7` | Subtle surface boundaries |
| `--radius` | `8px` | Theme corner radius |
| `--white` | `#ffffff` | White surfaces; inherited from base CSS |
| `--mint` | `#e7f2eb` | Supporting tint; inherited from base CSS |
| `--gold` | `#c5a466` | Supporting accent; inherited from base CSS |

Not every component color is tokenized. Reuse an existing component's local styles before adding a new color or token.

| Semantic use | Foreground | Background |
|---|---|---|
| Active navigation | `#146b46` | `#e1efe5` |
| Positive metric icon | `#197b46` | `#e5f2e9` |
| Bank metric icon | `#1c6689` | `#eaf0f8` |
| Attention metric icon | `#a36507` | `#fff1db` |
| Expense / risk metric icon | `#d02e37` | `#fee9e8` |
| Posted dashboard badge | `#218150` | `#e4f3e8` |
| Unpaid / overdue / reversed dashboard badge | `#d93338` | `#ffe7e7` |
| Partial / due-soon dashboard badge | `#ab741a` | `#fff0d7` |
| Form error | `#a34836` | `#fff0ed` |

These are existing styles, not a claim of verified contrast compliance. Status text and directional indicators must accompany meaningful colors.

## Typography

The current font stack is `'Segoe UI', Arial, sans-serif`, using system fonts. There is no downloaded brand font. Financial columns use tabular numerals.

| Role | Typical desktop size |
|---|---|
| Base body | 17.92px |
| Page / dashboard title | 35.84px |
| Dashboard subtitle | 16.64px |
| Dashboard panel title | 17.92px |
| Standard dashboard metric | 26.88px |
| Buttons and standard form inputs | 15.36px |
| Sidebar link | 14.08px |
| Sidebar group label | 11.52px, weight 700, black |
| Dashboard table body | 12.8px |
| Dashboard table heading | 11.52px |

These are role-specific values, not a universal type scale. Wide-screen and mobile rules adjust them; dashboard titles become 32px at 700px and below. Preserve the approved enlargement rather than rounding every size to a new scale. Allow labels and amounts to reflow instead of clipping important content.

## Shell and navigation

[Shell](src/components/shell.tsx) is the shared workspace frame.

- A fixed 260px sidebar contains branding, company selection, Dashboard and grouped Transactions, Accounts, Banking, Reports and Settings links.
- Active links use a pale green fill, stronger green text and a narrow green edge marker. Icons use Lucide outline SVGs.
- Planned routes are disabled, labelled “Planned” and rendered without a working link. Their presence does not mean the feature is delivered in this reference app.
- The header contains the sidebar toggle, company/year controls, search, overdue indicator, help and profile controls. Its minimum height is 63px, with room to grow.
- Global search covers transactions, customers and suppliers; Ctrl/Cmd K focuses it.
- Main content has a maximum width of 1800px. Standard desktop padding is 26px 30px; dashboard padding is 16px 32px 20px.
- The footer identifies Jinu Accounts and the company currency, with “Your ledger is the source of truth”.

At 700px and below, navigation becomes a 220px drawer with a backdrop and close control. Escape closes it. Desktop navigation can be collapsed. Preserve working company selection when header controls disappear at narrower widths.

## Surfaces, spacing and controls

White panels sit on the pale canvas with a 1px border and an 8px radius. Most panels have no shadow; menus use a restrained shadow to distinguish their overlay layer. Dashboard gaps are typically 12px, with compact internal padding. Reuse surrounding spacing rather than introducing a separate spacing framework.

| Pattern | Implementation rule |
|---|---|
| Primary button | Green fill, white text, 5px radius within the Jinu shell; concise verb-based label |
| Secondary button | White surface and muted green text; use `.button.secondary` |
| Text action | Use the existing linked-action treatment for report/source navigation |
| Input / textarea | White surface, subtle border, 6px radius; labels remain visible |
| Focus | Visible green `#278868` outline; input/select/textarea focus has no shared 4px offset |
| Disabled control | Preserve actual disabled behavior and subdued presentation |
| Notice | Tinted bordered block with readable explanation |
| Error | Warm red bordered block with actionable validation text |
| Empty state | Plain explanation and the relevant next step; no fabricated activity |

Use [Select](src/components/select.tsx) for existing dropdown patterns. It retains a hidden native select for form data and validation, with a visible button, searchable list and selected/disabled options. Opening focuses search; filtering uses displayed labels; no matches displays an explicit message. Preserve keyboard behavior and native form compatibility when modifying it.

Typical select triggers have a 44px minimum height, increasing to 46px on mobile. Compact header and chart variants override those dimensions. Existing option menus use a 6px radius and a green border. Some current controls and dense navigation links are smaller than standard touch-target recommendations; do not describe the current interface as fully accessibility-certified.

## Dashboard composition

[DashboardPage](src/components/dashboard-page.tsx) uses this hierarchy:

1. Title, company financial-position description, refresh timestamp and authorized Create Transaction menu.
2. Company/currency/as-of context and the monthly-activity basis.
3. Eight linked metrics: cash, bank, receivables, payables, revenue, expenses, net profit/loss and overdue receivables.
4. Revenue vs Expenses and Monthly Profit / Loss charts.
5. Recent Transactions, Outstanding Receivables and Upcoming Payables tables.
6. Expandable balance, comparison and reconciliation explanations.

Desktop metrics use four columns across two rows. Charts sit side by side. Recent activity occupies the left side of the lower grid, with two stacked outstanding-document lists on the right.

Metric cards combine a tinted circular icon, label, currency and amount, comparison text and source affordance. Favorable and unfavorable changes depend on the metric: a lower expense or overdue balance is favorable. Missing or zero-baseline comparison data is labelled “Comparison unavailable”.

[DashboardCharts](src/components/dashboard-charts.tsx) renders SVG charts with independent six/twelve-month selectors. Revenue bars use `#248652`; expenses use `#e7a03d`. Profit lines use green or red according to the values. Every chart includes an expandable exact-value table and source links. SVG geometry may use browser numbers; authoritative amounts remain decimal values. Missing history is distinct from recorded zero activity.

## Tables, reports and documents

- Use semantic tables with clear headings, explicit debit/credit columns where applicable, and right-aligned `.numeric` money columns.
- Keep currency and date basis visible. Format authoritative amounts through the existing money utilities; display precision must not change stored values.
- Use links on voucher numbers, document references, accounts and parties to expose sources. Preserve company, dates, filters and return context where supported.
- Place wide tables inside `.table-scroll`. Preserve internal horizontal scrolling instead of forcing body overflow or hiding financial columns.
- Use section, group and total rows to establish report hierarchy. Keep reconciliation and unresolved-state explanations adjacent to relevant totals.
- Documents use a paper-style panel with issuer/reference details, addresses, dates, line items, totals and footnotes. Keep actions separate from printed content.
- Posted history remains immutable. Present correction/reversal workflows and their dependencies rather than editable posted balances or deletion actions.

Dashboard lists show derived statuses including Posted, Reversed, Unpaid, Partially Paid, Due Soon and Overdue. The shared status colors do not imply identical accounting meaning; retain the visible label.

## Responsive behavior

The theme and base stylesheet have overlapping media queries. This table summarizes the Jinu shell/dashboard rules; individual form/report rules must also be checked.

| Width | Current layout behavior |
|---|---|
| 1600px and above | Larger metric text, padding and chart allowance |
| 1250px and below | Header company control hidden; lower dashboard grid stacks; outstanding lists become side by side |
| 950px and below | Two metric columns; charts and outstanding lists stack; help/name and secondary context are reduced |
| 700px and below | Drawer navigation, wrapping header, 17px 14px content padding, compact two-column metrics and smaller panel headings |

Base styles also adapt forms, document layouts and tables at 1200, 960, 900, 720, 700 and 600px. For example, general form controls enlarge to 20.48px at 720px and below, while compact theme controls have their own overrides. Do not assume one breakpoint or font rule governs every screen.

The login screen uses a dark green story panel and a white form panel on desktop, stacking on narrow screens. Onboarding uses a centered white card on a pale background. Reuse the shared brand in both.

## Accessibility and print

Preserve the skip link, labelled controls, visible focus, semantic headings, table headers, disabled states and text descriptions of financial status. Icon-only actions need accessible names. Charts need their accessible descriptions and exact-value alternatives. Keep searchable controls operable without a pointer.

Current keyboard support includes Ctrl/Cmd K for search and Escape for closing mobile navigation and select menus. This source review does not certify complete drawer focus management, every dropdown keyboard interaction, screen-reader support or color contrast.

Print styles hide navigation, headers, action controls and filters, remove shell margins and unnecessary panel decoration, and make financial tables available on paper. Dashboard print keeps four metric columns and two charts; document rows and totals avoid page breaks where specified. Preserve company/currency/date identity in report/document content when navigation and filters are absent. Physical printer and pagination behavior require separate checking.

## Maintaining the design

Reuse the existing shell, brand, page heading, panel, button, select, table and notice patterns. Keep presentation changes separate from accounting rules. Do not add a theme engine, component dependency or editable summary balance to reproduce an existing pattern.

For a UI change, check affected desktop/mobile flows, keyboard use, long labels, large amounts, validation, empty/unavailable states, company context, source returns and print where relevant. Follow [AGENTS.md](AGENTS.md) for required verification; production browser tests follow a fresh build, and fixtures must use the isolated test database.

Source anchors: [shared forms](src/components/forms.tsx), [operational forms](src/components/operations-forms.tsx), [operational pages](src/components/operations-pages.tsx), [report pages](src/components/report-pages.tsx), [settlement pages](src/components/settlement-pages.tsx) and [dashboard browser journeys](tests/browser/dashboard.spec.ts).

The [Dashboard Redesign Report](DASHBOARD_REDESIGN_REPORT.md) records the original release and its historical verification. Later approved typography, dropdown and reporting changes are recorded in the canonical progress log. This document was validated against source only; no application tests, build, live browser review or accessibility certification were performed for its creation.
