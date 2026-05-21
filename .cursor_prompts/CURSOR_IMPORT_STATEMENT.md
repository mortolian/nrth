You are working inside my existing Laravel application.

In an appropriate app domain Build a new “Bank Statement Import” feature with a clean, extensible architecture. The feature must support CSV and OFX imports in this version. PDF must not be implemented now, but the architecture must allow PDF import to be added later as a separate module, potentially using AI to extract transactions from arbitrary PDF bank statements.

Core requirements:

There has to be an import grouping with which the imported transactions can be associated, I was thinking that this can be an interface UI where an "account", not assiciated with any other type of account record in the app, this will be exclusively for imported bank account or credit card statement details.

1. Accounts

Each imported bank statement must be imported into a selected account.

If no suitable account structure exists, create:

accounts
- id
- name
- bank_name nullable
- account_number_last4 nullable
- currency default ZAR
- type nullable
- is_active boolean
- created_at
- updated_at

The import UI must allow the user to select the account before importing.

2. Import architecture

Do not tightly couple CSV or OFX parsing into the controller.

Create an extensible importer architecture using interfaces/contracts.

The importer contract should allow future file types like PDF, MT940, QIF, camt.053, etc.

Example interface:

interface BankStatementImporter
{
    public function supports(string $mimeType, string $extension): bool;

    public function parse(string $path, array $options = []): ParsedBankStatement;
}

The controller should ask the registry for the correct importer instead of knowing the file-specific logic.

3. Database structure

Keep the database simple but durable.

Create these tables:

bank_statement_imports
- id
- account_id
- original_filename
- stored_path
- file_type
- mime_type nullable
- file_hash
- status: pending, parsed, imported, failed
- total_rows nullable
- imported_rows nullable
- duplicate_rows nullable
- failed_rows nullable
- metadata json nullable
- error_message text nullable
- created_at
- updated_at

bank_transactions
- id
- account_id
- bank_statement_import_id nullable
- transaction_date
- value_date nullable
- description
- reference nullable
- amount decimal(18,2)
- currency char(3) default ZAR
- direction enum: debit, credit
- running_balance decimal(18,2) nullable
- source_hash
- duplicate_key
- metadata json nullable
- created_at
- updated_at

Add a unique index on duplicate_key where practical.

The duplicate key should be deterministic and should be based on:
- account_id
- transaction_date
- amount
- description
- reference if available

The system should not import duplicate transactions. So if the same statement is loaded twice by acceident, or another statement may have a few transactions overlapping a previous import, those should be checked and not written to the dataset again.

4. File storage

Save every original uploaded file. The original imported files should be kept, if anything goes wrong, those should be available to review, audit or re-import as a sort of backup on disk.

Use Laravel Storage.

Store files under:

bank-statements/{account_id}/{year}/{month}/

Save the file hash on bank_statement_imports.

If the exact same file was already imported, warn the user and prevent accidental re-import unless explicitly allowed later.

5. CSV import

CSV import should be flexible because banks do not use the same columns.

For this version, implement a simple mapping layer.

The import screen should allow:
- upload CSV
- select account
- detect headers
- preview first rows
- map fields:
  - transaction_date
  - description
  - amount OR debit/credit columns
  - reference optional
  - running_balance optional
  - value_date optional

After the user confirms mapping, import the transactions.

Save the mapping in metadata for now. Do not overbuild bank profile management yet, but structure the code so reusable profiles can be added later.

CSV parser must handle:
- comma and semicolon separators
- quoted values
- different date formats
- negative amounts
- debit/credit split columns
- thousands separators
- decimal comma or decimal point where reasonably possible

6. OFX import

Add OFX import as a separate importer class.

Parse:
- transaction date
- amount
- memo/name/description
- FITID as reference if available
- balance if available
- currency if available

If OFX parsing requires a package, choose a maintained Laravel/PHP-compatible package if available, otherwise implement a small parser with clear comments and tests.

7. Import workflow

Create a simple UI flow:

Step 1:
- Select account
- Upload file
- Choose file type automatically where possible

Step 2:
- If CSV: show mapping screen and preview rows
- If OFX: show parsed transaction preview

Step 3:
- Show import summary:
  - total transactions found
  - new transactions
  - duplicates
  - errors

Step 4:
- Confirm import

The UI should be fast and simple. Avoid unnecessary complexity.

Use existing UI conventions in the project. If the project uses Filament, build this as a Filament resource/page. If it uses Blade, build Blade views. If both exist, prefer Filament.

8. Validation

Validate:
- account is required
- file is required
- only csv, txt, ofx allowed for now
- max file size should be reasonable, e.g. 10MB
- mapped required fields must exist for CSV

9. Tests

Create tests for:

- CSV importer with signed amount column
- CSV importer with debit and credit columns
- OFX importer
- duplicate detection
- file saved correctly
- transactions linked to selected account
- same transaction not imported twice

Use fixtures in:

tests/Fixtures/bank-statements/

Create small sample files for CSV and OFX.

10. Code quality

Make sure to follow the app domain approach for structuring this features code files.

Make sure to follow Laravel best practices.

Keep the implementation clean and modular.

Do not put parsing logic in controllers.

Use DTOs for parsed transactions.

Use services for importing and duplicate detection.

Use database transactions when writing imported transactions.

Add comments only where the logic is non-obvious.

11. Expected final output

Implement:
- migrations
- models
- importer contract
- CSV importer
- OFX importer
- registry
- import service
- duplicate detector
- UI pages
- validation
- tests
- sample fixtures

After implementing, provide a short summary of:
- files created
- how to use the import feature
- how to add a future PDF importer module