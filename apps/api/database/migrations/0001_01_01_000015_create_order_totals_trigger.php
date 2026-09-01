<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION orders_recalc_total_cents() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    UPDATE orders
                       SET total_cents = COALESCE(
                           (SELECT SUM(line_total_cents) FROM order_items WHERE order_id = OLD.order_id),
                           0
                       )
                     WHERE id = OLD.order_id;
                ELSE
                    UPDATE orders
                       SET total_cents = COALESCE(
                           (SELECT SUM(line_total_cents) FROM order_items WHERE order_id = NEW.order_id),
                           0
                       )
                     WHERE id = NEW.order_id;
                END IF;

                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
            SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER orders_recalc_total_cents_trigger
            AFTER INSERT OR UPDATE OR DELETE ON order_items
            FOR EACH ROW EXECUTE FUNCTION orders_recalc_total_cents();
            SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS orders_recalc_total_cents_trigger ON order_items');
        DB::statement('DROP FUNCTION IF EXISTS orders_recalc_total_cents()');
    }
};
