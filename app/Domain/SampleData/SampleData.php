<?php

namespace App\Domain\SampleData;

/**
 * Identity of the disposable demo business. Re-running the loader deletes only this user and team.
 */
final class SampleData
{
    public const EMAIL = 'sample@northwind.example';

    public const PASSWORD = 'password';

    public const BUSINESS = 'Northwind Studio';

    /** February, so the year runs 1 March – end of February. */
    public const FINANCIAL_YEAR_END_MONTH = 2;
}
