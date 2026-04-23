# Graph Report - .  (2026-04-23)

## Corpus Check
- 98 files · ~127,547 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 1125 nodes · 2737 edges · 93 communities detected
- Extraction: 98% EXTRACTED · 2% INFERRED · 0% AMBIGUOUS · INFERRED: 61 edges (avg confidence: 0.8)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- [[_COMMUNITY_Chart.Min & N()|Chart.Min & N()]]
- [[_COMMUNITY_Phpmailer & .Presend()|Phpmailer & .Presend()]]
- [[_COMMUNITY_.Ishorizontal() & .Update()|.Ishorizontal() & .Update()]]
- [[_COMMUNITY_Ns() & Bn|Ns() & Bn]]
- [[_COMMUNITY_An() & .Update()|An() & .Update()]]
- [[_COMMUNITY_Js() & .Update()|Js() & .Update()]]
- [[_COMMUNITY_Receipts & Issue_Receipt_Ticket()|Receipts & Issue_Receipt_Ticket()]]
- [[_COMMUNITY_No & En|No & En]]
- [[_COMMUNITY_Smtp & .Smtpconnect()|Smtp & .Smtpconnect()]]
- [[_COMMUNITY_Zt() & Bt|Zt() & Bt]]
- [[_COMMUNITY_Sweetalert-Utils & .Addeventlistener()|Sweetalert-Utils & .Addeventlistener()]]
- [[_COMMUNITY_Sn & Os()|Sn & Os()]]
- [[_COMMUNITY_Tn & ._Each()|Tn & ._Each()]]
- [[_COMMUNITY_Jn & .Update()|Jn & .Update()]]
- [[_COMMUNITY_Helpers & Flash()|Helpers & Flash()]]
- [[_COMMUNITY_De & Ce()|De & Ce()]]
- [[_COMMUNITY_Receipt_Pdf & Receipt_Pdf_Normalize_Text()|Receipt_Pdf & Receipt_Pdf_Normalize_Text()]]
- [[_COMMUNITY_Rs & .Acquirecontext()|Rs & .Acquirecontext()]]
- [[_COMMUNITY_Avatar & Avatar|Avatar & Avatar]]
- [[_COMMUNITY_Runner & Get_Db()|Runner & Get_Db()]]
- [[_COMMUNITY_Catalog & Esc()|Catalog & Esc()]]
- [[_COMMUNITY_Config & Cfg_Detect_Base_Url()|Config & Cfg_Detect_Base_Url()]]
- [[_COMMUNITY_Role_Landing_Path() & Auth-Helpers|Role_Landing_Path() & Auth-Helpers]]
- [[_COMMUNITY_Csrf_Token() & Csrf_Verify()|Csrf_Token() & Csrf_Verify()]]
- [[_COMMUNITY_Exception & .Errormessage()|Exception & .Errormessage()]]
- [[_COMMUNITY_View & Esc()|View & Esc()]]
- [[_COMMUNITY_Audit-Log & Audit_Url()|Audit-Log & Audit_Url()]]
- [[_COMMUNITY_Libris-Compat & Hasown()|Libris-Compat & Hasown()]]
- [[_COMMUNITY_Database & Cfg_Env()|Database & Cfg_Env()]]
- [[_COMMUNITY_H() & Catalog-Add|H() & Catalog-Add]]
- [[_COMMUNITY_H() & Catalog-Edit|H() & Catalog-Edit]]
- [[_COMMUNITY_H() & Catalog|H() & Catalog]]
- [[_COMMUNITY_Test-Receipt-Pdf & T_Assert()|Test-Receipt-Pdf & T_Assert()]]
- [[_COMMUNITY_Test-Receipt-Success-Modal & T_Assert()|Test-Receipt-Success-Modal & T_Assert()]]
- [[_COMMUNITY_403|403]]
- [[_COMMUNITY_Book-Cover-Public|Book-Cover-Public]]
- [[_COMMUNITY_Bootstrap|Bootstrap]]
- [[_COMMUNITY_Check-Email|Check-Email]]
- [[_COMMUNITY_Index|Index]]
- [[_COMMUNITY_Login|Login]]
- [[_COMMUNITY_Logout|Logout]]
- [[_COMMUNITY_Register|Register]]
- [[_COMMUNITY_About|About]]
- [[_COMMUNITY_Index|Index]]
- [[_COMMUNITY_Reports|Reports]]
- [[_COMMUNITY_Settings|Settings]]
- [[_COMMUNITY_Upload-Avatar|Upload-Avatar]]
- [[_COMMUNITY_Users|Users]]
- [[_COMMUNITY_Apply-Migration|Apply-Migration]]
- [[_COMMUNITY_Run-Migration|Run-Migration]]
- [[_COMMUNITY_Search-Suggestions|Search-Suggestions]]
- [[_COMMUNITY_Create|Create]]
- [[_COMMUNITY_Get|Get]]
- [[_COMMUNITY_Pdf|Pdf]]
- [[_COMMUNITY_Print-Meta|Print-Meta]]
- [[_COMMUNITY_Qr|Qr]]
- [[_COMMUNITY_Reprint|Reprint]]
- [[_COMMUNITY_Login|Login]]
- [[_COMMUNITY_Register|Register]]
- [[_COMMUNITY_Verify|Verify]]
- [[_COMMUNITY_Catalog|Catalog]]
- [[_COMMUNITY_Index|Index]]
- [[_COMMUNITY_My_Books|My_Books]]
- [[_COMMUNITY_Profile|Profile]]
- [[_COMMUNITY_Renew|Renew]]
- [[_COMMUNITY_Reserve|Reserve]]
- [[_COMMUNITY_Auth_Guard|Auth_Guard]]
- [[_COMMUNITY_Head|Head]]
- [[_COMMUNITY_Receipt-Success-Modal|Receipt-Success-Modal]]
- [[_COMMUNITY_Sidebar-Admin|Sidebar-Admin]]
- [[_COMMUNITY_Sidebar-Borrower|Sidebar-Borrower]]
- [[_COMMUNITY_Sidebar-Librarian|Sidebar-Librarian]]
- [[_COMMUNITY_Catalog-Delete|Catalog-Delete]]
- [[_COMMUNITY_Checkin|Checkin]]
- [[_COMMUNITY_Checkout|Checkout]]
- [[_COMMUNITY_Index|Index]]
- [[_COMMUNITY_Pay-Fine|Pay-Fine]]
- [[_COMMUNITY_Print-Record|Print-Record]]
- [[_COMMUNITY_Records-Print|Records-Print]]
- [[_COMMUNITY_Records|Records]]
- [[_COMMUNITY_Reservations|Reservations]]
- [[_COMMUNITY_Index|Index]]
- [[_COMMUNITY_Kiosk|Kiosk]]
- [[_COMMUNITY_Lint-Receipts-Phase1|Lint-Receipts-Phase1]]
- [[_COMMUNITY_Search-Suggestions|Search-Suggestions]]
- [[_COMMUNITY_Config|Config]]
- [[_COMMUNITY_Constants|Constants]]
- [[_COMMUNITY_Database|Database]]
- [[_COMMUNITY_Auth_Guard|Auth_Guard]]
- [[_COMMUNITY_Sidebar-Admin|Sidebar-Admin]]
- [[_COMMUNITY_Sidebar-Borrower|Sidebar-Borrower]]
- [[_COMMUNITY_Sidebar-Librarian|Sidebar-Librarian]]
- [[_COMMUNITY_Main|Main]]

## God Nodes (most connected - your core abstractions)
1. `PHPMailer` - 130 edges
2. `js()` - 71 edges
3. `an()` - 61 edges
4. `ns()` - 55 edges
5. `SMTP` - 45 edges
6. `n()` - 38 edges
7. `no` - 32 edges
8. `s()` - 29 edges
9. `va` - 28 edges
10. `o()` - 27 edges

## Surprising Connections (you probably didn't know these)
- `run_migration()` --calls--> `get_db()`  [INFERRED]
  admin\migrations\runner.php → includes\db.php
- `receipt_api_phase1_guard()` --calls--> `receipt_phase1_enabled()`  [INFERRED]
  api\receipts\_bootstrap.php → includes\receipts.php
- `receipt_api_receipt_from_request()` --calls--> `get_receipt_ticket_by_id()`  [INFERRED]
  api\receipts\_bootstrap.php → includes\receipts.php
- `receipt_api_receipt_from_request()` --calls--> `get_receipt_ticket_by_number()`  [INFERRED]
  api\receipts\_bootstrap.php → includes\receipts.php
- `showPasswordFeedback()` --calls--> `showSuccess()`  [INFERRED]
  assets\js\admin-account.js → assets\js\sweetalert-utils.js

## Communities

### Community 0 - "Chart.Min & N()"
Cohesion: 0.02
Nodes (115): _(), a(), aa(), ao(), b(), be(), beforeDatasetDraw(), beforeDatasetsDraw() (+107 more)

### Community 1 - "Phpmailer & .Presend()"
Cohesion: 0.03
Nodes (5): send_account_status_email(), send_smtp_mail(), smtp_normalize_password(), smtp_normalize_secure(), PHPMailer

### Community 2 - ".Ishorizontal() & .Update()"
Cohesion: 0.06
Nodes (37): afterDraw(), afterEvent(), afterUpdate(), ai(), ba(), da(), ea(), f() (+29 more)

### Community 3 - "Ns() & Bn"
Cohesion: 0.04
Nodes (19): addBox(), As(), beforeUpdate(), bn, Bs(), fs(), initialize(), k() (+11 more)

### Community 4 - "An() & .Update()"
Cohesion: 0.05
Nodes (18): afterDatasetsUpdate(), an(), cn(), dn(), ds(), ei(), ge(), je() (+10 more)

### Community 5 - "Js() & .Update()"
Cohesion: 0.05
Nodes (11): Ae(), ci(), d(), fe(), Fi(), js(), ks(), Ue() (+3 more)

### Community 6 - "Receipts & Issue_Receipt_Ticket()"
Cohesion: 0.06
Nodes (48): change_user_password(), log_admin_action(), save_setting(), receipt_api_actor(), receipt_api_json(), receipt_api_phase1_guard(), receipt_api_read_json_body(), receipt_api_receipt_from_request() (+40 more)

### Community 7 - "No & En"
Cohesion: 0.06
Nodes (20): beforeLayout(), buildLookupTable(), En, Fo(), _generate(), getDecimalForValue(), _getTimestampsForTable(), ia() (+12 more)

### Community 8 - "Smtp & .Smtpconnect()"
Cohesion: 0.1
Nodes (1): SMTP

### Community 9 - "Zt() & Bt"
Cohesion: 0.08
Nodes (16): at(), Bi(), bt, e(), gt(), jt(), kt(), mt() (+8 more)

### Community 10 - "Sweetalert-Utils & .Addeventlistener()"
Cohesion: 0.11
Nodes (19): getScore(), renderStrength(), showPasswordFeedback(), ct(), ms(), ys(), initMobileSidebar(), confirmAction() (+11 more)

### Community 11 - "Sn & Os()"
Cohesion: 0.14
Nodes (5): Cs, nn(), os(), pi(), sn

### Community 12 - "Tn & ._Each()"
Cohesion: 0.16
Nodes (2): addElements(), tn

### Community 13 - "Jn & .Update()"
Cohesion: 0.23
Nodes (2): h(), jn

### Community 14 - "Helpers & Flash()"
Cohesion: 0.15
Nodes (0): 

### Community 15 - "De & Ce()"
Cohesion: 0.24
Nodes (5): ce(), de, dt(), he(), Oe()

### Community 16 - "Receipt_Pdf & Receipt_Pdf_Normalize_Text()"
Cohesion: 0.38
Nodes (11): receipt_pdf_escape_text(), receipt_pdf_extract_items_services(), receipt_pdf_filename(), receipt_pdf_first_non_empty(), receipt_pdf_lines_from_model(), receipt_pdf_normalize_text(), receipt_pdf_render(), receipt_pdf_safe_token() (+3 more)

### Community 17 - "Rs & .Acquirecontext()"
Cohesion: 0.22
Nodes (1): rs

### Community 18 - "Avatar & Avatar"
Cohesion: 0.57
Nodes (6): delete_user_avatar(), get_avatar_display(), process_avatar_upload(), save_avatar_upload(), update_user_avatar(), validate_avatar_upload()

### Community 19 - "Runner & Get_Db()"
Cohesion: 0.29
Nodes (2): get_db(), run_migration()

### Community 20 - "Catalog & Esc()"
Cohesion: 0.7
Nodes (3): esc(), render_book_card(), render_book_section()

### Community 21 - "Config & Cfg_Detect_Base_Url()"
Cohesion: 0.5
Nodes (0): 

### Community 22 - "Role_Landing_Path() & Auth-Helpers"
Cohesion: 0.83
Nodes (3): redirect_authenticated_user(), resolve_role(), role_landing_path()

### Community 23 - "Csrf_Token() & Csrf_Verify()"
Cohesion: 0.67
Nodes (2): csrf_token(), csrf_verify()

### Community 24 - "Exception & .Errormessage()"
Cohesion: 0.5
Nodes (1): Exception

### Community 25 - "View & Esc()"
Cohesion: 0.5
Nodes (0): 

### Community 26 - "Audit-Log & Audit_Url()"
Cohesion: 1.0
Nodes (2): audit_url(), sort_header()

### Community 27 - "Libris-Compat & Hasown()"
Cohesion: 1.0
Nodes (0): 

### Community 28 - "Database & Cfg_Env()"
Cohesion: 1.0
Nodes (0): 

### Community 29 - "H() & Catalog-Add"
Cohesion: 1.0
Nodes (0): 

### Community 30 - "H() & Catalog-Edit"
Cohesion: 1.0
Nodes (0): 

### Community 31 - "H() & Catalog"
Cohesion: 1.0
Nodes (0): 

### Community 32 - "Test-Receipt-Pdf & T_Assert()"
Cohesion: 1.0
Nodes (0): 

### Community 33 - "Test-Receipt-Success-Modal & T_Assert()"
Cohesion: 1.0
Nodes (0): 

### Community 34 - "403"
Cohesion: 1.0
Nodes (0): 

### Community 35 - "Book-Cover-Public"
Cohesion: 1.0
Nodes (0): 

### Community 36 - "Bootstrap"
Cohesion: 1.0
Nodes (0): 

### Community 37 - "Check-Email"
Cohesion: 1.0
Nodes (0): 

### Community 38 - "Index"
Cohesion: 1.0
Nodes (0): 

### Community 39 - "Login"
Cohesion: 1.0
Nodes (0): 

### Community 40 - "Logout"
Cohesion: 1.0
Nodes (0): 

### Community 41 - "Register"
Cohesion: 1.0
Nodes (0): 

### Community 42 - "About"
Cohesion: 1.0
Nodes (0): 

### Community 43 - "Index"
Cohesion: 1.0
Nodes (0): 

### Community 44 - "Reports"
Cohesion: 1.0
Nodes (0): 

### Community 45 - "Settings"
Cohesion: 1.0
Nodes (0): 

### Community 46 - "Upload-Avatar"
Cohesion: 1.0
Nodes (0): 

### Community 47 - "Users"
Cohesion: 1.0
Nodes (0): 

### Community 48 - "Apply-Migration"
Cohesion: 1.0
Nodes (0): 

### Community 49 - "Run-Migration"
Cohesion: 1.0
Nodes (0): 

### Community 50 - "Search-Suggestions"
Cohesion: 1.0
Nodes (0): 

### Community 51 - "Create"
Cohesion: 1.0
Nodes (0): 

### Community 52 - "Get"
Cohesion: 1.0
Nodes (0): 

### Community 53 - "Pdf"
Cohesion: 1.0
Nodes (0): 

### Community 54 - "Print-Meta"
Cohesion: 1.0
Nodes (0): 

### Community 55 - "Qr"
Cohesion: 1.0
Nodes (0): 

### Community 56 - "Reprint"
Cohesion: 1.0
Nodes (0): 

### Community 57 - "Login"
Cohesion: 1.0
Nodes (0): 

### Community 58 - "Register"
Cohesion: 1.0
Nodes (0): 

### Community 59 - "Verify"
Cohesion: 1.0
Nodes (0): 

### Community 60 - "Catalog"
Cohesion: 1.0
Nodes (0): 

### Community 61 - "Index"
Cohesion: 1.0
Nodes (0): 

### Community 62 - "My_Books"
Cohesion: 1.0
Nodes (0): 

### Community 63 - "Profile"
Cohesion: 1.0
Nodes (0): 

### Community 64 - "Renew"
Cohesion: 1.0
Nodes (0): 

### Community 65 - "Reserve"
Cohesion: 1.0
Nodes (0): 

### Community 66 - "Auth_Guard"
Cohesion: 1.0
Nodes (0): 

### Community 67 - "Head"
Cohesion: 1.0
Nodes (0): 

### Community 68 - "Receipt-Success-Modal"
Cohesion: 1.0
Nodes (0): 

### Community 69 - "Sidebar-Admin"
Cohesion: 1.0
Nodes (0): 

### Community 70 - "Sidebar-Borrower"
Cohesion: 1.0
Nodes (0): 

### Community 71 - "Sidebar-Librarian"
Cohesion: 1.0
Nodes (0): 

### Community 72 - "Catalog-Delete"
Cohesion: 1.0
Nodes (0): 

### Community 73 - "Checkin"
Cohesion: 1.0
Nodes (0): 

### Community 74 - "Checkout"
Cohesion: 1.0
Nodes (0): 

### Community 75 - "Index"
Cohesion: 1.0
Nodes (0): 

### Community 76 - "Pay-Fine"
Cohesion: 1.0
Nodes (0): 

### Community 77 - "Print-Record"
Cohesion: 1.0
Nodes (0): 

### Community 78 - "Records-Print"
Cohesion: 1.0
Nodes (0): 

### Community 79 - "Records"
Cohesion: 1.0
Nodes (0): 

### Community 80 - "Reservations"
Cohesion: 1.0
Nodes (0): 

### Community 81 - "Index"
Cohesion: 1.0
Nodes (0): 

### Community 82 - "Kiosk"
Cohesion: 1.0
Nodes (0): 

### Community 83 - "Lint-Receipts-Phase1"
Cohesion: 1.0
Nodes (0): 

### Community 84 - "Search-Suggestions"
Cohesion: 1.0
Nodes (0): 

### Community 85 - "Config"
Cohesion: 1.0
Nodes (0): 

### Community 86 - "Constants"
Cohesion: 1.0
Nodes (0): 

### Community 87 - "Database"
Cohesion: 1.0
Nodes (0): 

### Community 88 - "Auth_Guard"
Cohesion: 1.0
Nodes (0): 

### Community 89 - "Sidebar-Admin"
Cohesion: 1.0
Nodes (0): 

### Community 90 - "Sidebar-Borrower"
Cohesion: 1.0
Nodes (0): 

### Community 91 - "Sidebar-Librarian"
Cohesion: 1.0
Nodes (0): 

### Community 92 - "Main"
Cohesion: 1.0
Nodes (0): 

## Knowledge Gaps
- **Thin community `Libris-Compat & Hasown()`** (2 nodes): `libris-compat.js`, `hasOwn()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Database & Cfg_Env()`** (2 nodes): `database.php`, `cfg_env()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `H() & Catalog-Add`** (2 nodes): `h()`, `catalog-add.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `H() & Catalog-Edit`** (2 nodes): `h()`, `catalog-edit.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `H() & Catalog`** (2 nodes): `h()`, `catalog.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Test-Receipt-Pdf & T_Assert()`** (2 nodes): `test-receipt-pdf.php`, `t_assert()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Test-Receipt-Success-Modal & T_Assert()`** (2 nodes): `test-receipt-success-modal.php`, `t_assert()`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `403`** (1 nodes): `403.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Book-Cover-Public`** (1 nodes): `book-cover-public.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Bootstrap`** (1 nodes): `bootstrap.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Check-Email`** (1 nodes): `check-email.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Index`** (1 nodes): `index.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Login`** (1 nodes): `login.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Logout`** (1 nodes): `logout.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Register`** (1 nodes): `register.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `About`** (1 nodes): `about.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Index`** (1 nodes): `index.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Reports`** (1 nodes): `reports.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Settings`** (1 nodes): `settings.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Upload-Avatar`** (1 nodes): `upload-avatar.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Users`** (1 nodes): `users.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Apply-Migration`** (1 nodes): `apply-migration.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Run-Migration`** (1 nodes): `run-migration.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Search-Suggestions`** (1 nodes): `search-suggestions.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Create`** (1 nodes): `create.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Get`** (1 nodes): `get.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Pdf`** (1 nodes): `pdf.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Print-Meta`** (1 nodes): `print-meta.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Qr`** (1 nodes): `qr.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Reprint`** (1 nodes): `reprint.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Login`** (1 nodes): `login.js`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Register`** (1 nodes): `register.js`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Verify`** (1 nodes): `verify.js`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Catalog`** (1 nodes): `catalog.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Index`** (1 nodes): `index.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `My_Books`** (1 nodes): `my_books.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Profile`** (1 nodes): `profile.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Renew`** (1 nodes): `renew.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Reserve`** (1 nodes): `reserve.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Auth_Guard`** (1 nodes): `auth_guard.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Head`** (1 nodes): `head.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Receipt-Success-Modal`** (1 nodes): `receipt-success-modal.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Sidebar-Admin`** (1 nodes): `sidebar-admin.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Sidebar-Borrower`** (1 nodes): `sidebar-borrower.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Sidebar-Librarian`** (1 nodes): `sidebar-librarian.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Catalog-Delete`** (1 nodes): `catalog-delete.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Checkin`** (1 nodes): `checkin.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Checkout`** (1 nodes): `checkout.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Index`** (1 nodes): `index.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Pay-Fine`** (1 nodes): `pay-fine.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Print-Record`** (1 nodes): `print-record.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Records-Print`** (1 nodes): `records-print.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Records`** (1 nodes): `records.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Reservations`** (1 nodes): `reservations.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Index`** (1 nodes): `index.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Kiosk`** (1 nodes): `kiosk.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Lint-Receipts-Phase1`** (1 nodes): `lint-receipts-phase1.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Search-Suggestions`** (1 nodes): `search-suggestions.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Config`** (1 nodes): `config.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Constants`** (1 nodes): `constants.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Database`** (1 nodes): `database.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Auth_Guard`** (1 nodes): `auth_guard.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Sidebar-Admin`** (1 nodes): `sidebar-admin.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Sidebar-Borrower`** (1 nodes): `sidebar-borrower.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Sidebar-Librarian`** (1 nodes): `sidebar-librarian.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.
- **Thin community `Main`** (1 nodes): `main.php`
  Too small to be a meaningful cluster - may be noise or needs more connections extracted.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `dt()` connect `De & Ce()` to `Chart.Min & N()`, `Smtp & .Smtpconnect()`, `An() & .Update()`?**
  _High betweenness centrality (0.201) - this node is a cross-community bridge._
- **Why does `PHPMailer` connect `Phpmailer & .Presend()` to `Smtp & .Smtpconnect()`?**
  _High betweenness centrality (0.147) - this node is a cross-community bridge._
- **Why does `build_receipt_view_model()` connect `Receipts & Issue_Receipt_Ticket()` to `No & En`?**
  _High betweenness centrality (0.064) - this node is a cross-community bridge._
- **Should `Chart.Min & N()` be split into smaller, more focused modules?**
  _Cohesion score 0.02 - nodes in this community are weakly interconnected._
- **Should `Phpmailer & .Presend()` be split into smaller, more focused modules?**
  _Cohesion score 0.03 - nodes in this community are weakly interconnected._
- **Should `.Ishorizontal() & .Update()` be split into smaller, more focused modules?**
  _Cohesion score 0.06 - nodes in this community are weakly interconnected._
- **Should `Ns() & Bn` be split into smaller, more focused modules?**
  _Cohesion score 0.04 - nodes in this community are weakly interconnected._