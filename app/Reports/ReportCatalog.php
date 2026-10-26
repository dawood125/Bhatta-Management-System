<?php

namespace App\Reports;

use App\Enums\Permission;
use App\Models\User;

/**
 * Every report of the system in one list, grouped by area, as shown on the Reports page. A
 * report is listed only for those who may open it. Several live on their module's own page
 * (with filters and print), the rest under /reports.
 */
class ReportCatalog
{
    /**
     * @return list<array{label: string, items: list<array{title: string, description: string, route: string, icon: string}>}>
     */
    public static function for(User $user): array
    {
        $groups = [
            [
                'label' => __('Money'),
                'items' => [
                    [__('Profit and loss'), __('Income and costs of a period, and the profit.'), 'reports.profit-and-loss', 'trending-up', Permission::FinancialReportsView],
                    [__('Balance sheet'), __('What the kiln owns, owes and is worth on a date.'), 'reports.balance-sheet', 'landmark', Permission::FinancialReportsView],
                    [__('Trial balance'), __('Every account\'s balance on a date; debits equal credits.'), 'reports.trial-balance', 'scale', Permission::FinancialReportsView],
                    [__('Day close'), __('Count the cash against the books and close the day.'), 'reports.day-close', 'lock', Permission::DaysClose],
                    [__('Cash and bank books'), __('Every movement of an account with a running balance.'), 'accounts.index', 'wallet', Permission::AccountsView],
                    [__('Payments and receipts'), __('Vouchers of a period with totals paid and received.'), 'vouchers.index', 'arrow-left-right', Permission::AccountsView],
                    [__('Expenses by category'), __('Kiln expenses of a period, per category.'), 'expenses.index', 'receipt-text', Permission::AccountsView],
                ],
            ],
            [
                'label' => __('Customers and suppliers'),
                'items' => [
                    [__('Who owes us'), __('What each customer owes, by the age of their unpaid sales.'), 'reports.receivables', 'hourglass', Permission::PartiesView],
                    [__('Party statements and balances'), __('Receivable and payable balances, and each party\'s statement.'), 'parties.index', 'contact', Permission::PartiesView],
                ],
            ],
            [
                'label' => __('Sales'),
                'items' => [
                    [__('Sales summary'), __('Sales of a period by grade, by customer and by agent.'), 'reports.sales', 'shopping-cart', Permission::SalesView],
                    [__('Deliveries'), __('Every trip with the vehicle, driver and carriage.'), 'sales.deliveries', 'truck', Permission::SalesView],
                    [__('Drivers due'), __('Carriage owed to each driver.'), 'sales.drivers', 'hand-coins', Permission::SalesView],
                ],
            ],
            [
                'label' => __('Kiln'),
                'items' => [
                    [__('Production summary'), __('Stock of every stage and grade, and the work done in a period.'), 'reports.production', 'factory', Permission::ProductionView],
                    [__('Daily production'), __('What happened at each stage on one day.'), 'production.day', 'calendar-days', Permission::ProductionView],
                    [__('Cost per 1,000 bricks'), __('What 1,000 bricks cost and sold for in a period.'), 'reports.costs', 'calculator', Permission::FinancialReportsView],
                    [__('Material and fuel cost'), __('Coal (Kola), Bora and every material: bought, used and in stock.'), 'materials.report', 'package', Permission::MaterialsView],
                    [__('Labour cost'), __('Wages of a period, per labour category.'), 'labour.work', 'hard-hat', Permission::LabourView],
                ],
            ],
        ];

        $visible = [];
        foreach ($groups as $group) {
            $items = [];
            foreach ($group['items'] as [$title, $description, $route, $icon, $permission]) {
                if ($user->can($permission->value)) {
                    $items[] = ['title' => $title, 'description' => $description, 'route' => $route, 'icon' => $icon];
                }
            }

            if ($items !== []) {
                $visible[] = ['label' => $group['label'], 'items' => $items];
            }
        }

        return $visible;
    }
}
