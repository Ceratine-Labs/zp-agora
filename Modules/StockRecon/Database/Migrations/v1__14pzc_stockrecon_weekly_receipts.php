<?php

use App\Support\Database\ProcedureMigration;
use Illuminate\Support\Facades\DB;

/** Source families from customer reports1305,1262 and1319; reads only. */
return new class extends ProcedureMigration
{
    public function up(): void
    {
        $schema = str_replace(']', ']]', config('agora.schema'));
        $erp = str_replace(']', ']]', config('agora.source_databases.erp'));
        $landing = str_replace(']', ']]', config('agora.source_databases.pos_landing'));
        $reference = str_replace(']', ']]', config('agora.source_databases.pos_reference'));
        DB::unprepared(<<<SQL
            CREATE OR ALTER VIEW [{$schema}].vw_StockWeeklySupplier AS
            SELECT t.MIST_BranchId AS BranchId, t.POSSystemId, RTRIM(p.POSSystemName) AS POSSystemName,
                   t.TransactionDate, t.EODDate, t.TransactionNumber, t.TransactionType,
                   t.BusinessPartnerCode, t.BusinessPartnerName, t.DocumentNumber,
                   t.ItemCode, t.ItemDescription, t.ItemUOM, t.PackSize,
                   t.Qty, t.UnitCostPriceExcl, t.LineTotalAmountExcl,
                   t.LineTotalAmountVat, t.LineTotalAmountIncl
            FROM [{$erp}].dbo.MIST_Trans_Supplier t
            JOIN [{$reference}].dbo.POSSystems p ON p.POSSystemId = t.POSSystemId
            WHERE RTRIM(p.POSSystemName) = 'Aura' OR p.POSSystemName LIKE '%ARCH%';
            SQL);
        DB::unprepared(<<<SQL
            CREATE OR ALTER VIEW [{$schema}].vw_StockWeeklyPilotPurchase AS
            SELECT p.MIST_BranchId AS BranchId, p.purchaseID AS SourceId,
                   p.D AS PurchaseDate, p.PROD AS POSCode, p.ITEM AS ItemDescription,
                   p.UNIT AS Unit, p.QTY AS Quantity, p.COST AS CostExcl,
                   p.VAT AS Vat, p.SUPP AS SupplierCode, p.SUPPLIER AS SupplierName,
                   p.SREF AS InvoiceReference, p.PREF AS PostingReference, p.CAT AS Category
            FROM [{$landing}].dbo.PILOT_Purchases p;
            SQL);
        parent::up();
    }

    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_WeeklyReceipts.sql'];
    }
};
