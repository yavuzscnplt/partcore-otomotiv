<?php

declare(strict_types=1);

/**
 * KOD ÖRNEĞİ — Banka ekstresi satırını müşteriyle eşleştirme.
 *
 * PROBLEM: Bayi ödemesi bankaya düşer, açıklama alanı ise standartsızdır:
 * "OZKARDESLER OTO SAN TIC HAVALE", "MUSTERI 1042 ODEME", bazen sadece IBAN.
 * Bu satırı doğru cariye yazmak gerekir — yanlış eşleştirme muhasebeyi bozar
 * ve fark edilmesi haftalar alır.
 *
 * YAKLAŞIM: Sıralı strateji + güven skoru. Güvenilirden zayıfa doğru denenir;
 * ilk kesin eşleşmede durulur. Eşiğin (85) altındaki sonuçlar eşleştirilmez,
 * kullanıcıya *öneri* olarak gösterilir.
 *
 * ASIL TASARIM KARARI — içe aktarma cari hesaba HİÇ DOKUNMAZ. Satırlar bir
 * bekleme alanına düşer; tahsilat ancak kullanıcı onaylayınca oluşur.
 * "Otomatik %95 doğru" bir sistem, %5'lik hatayı sessizce muhasebeye yazardı.
 *
 * Not: Bu betiğin çağırdığı Action'lar (ReconcileStatementLine) ve MT940/CSV
 * okuyucuları özeldir; burada eşleştirme mantığı gösterilmektedir.
 */

namespace App\Modules\Banking\Services;

use App\Models\Customer;
use App\Modules\Banking\DTOs\StatementLineData;
use Illuminate\Support\Collection;

final class CustomerMatcher
{
    /**
     * Bu eşiğin altındaki hiçbir sonuç otomatik eşleştirilmez.
     * Tereddütlü durumda karar insana bırakılır.
     */
    public const AUTO_MATCH_THRESHOLD = 85;

    /** @var Collection<int, Customer>|null */
    private ?Collection $customers = null;

    /**
     * @return array{customer_id: int|null, confidence: int, reason: string|null}
     */
    public function match(StatementLineData $line): array
    {
        $customers = $this->customers();
        $haystack = $this->normalize($line->description.' '.($line->counterpartyName ?? ''));

        // 1) IBAN tam eşleşme (98) — en güvenilir sinyal.
        if ($line->counterpartyIban !== null) {
            $iban = strtoupper(str_replace(' ', '', $line->counterpartyIban));
            $hit = $customers->first(
                fn (Customer $c) => $c->iban !== null
                    && strtoupper(str_replace(' ', '', $c->iban)) === $iban
            );
            if ($hit !== null) {
                return $this->result($hit->id, 98, 'IBAN eşleşmesi');
            }
        }

        // 2) Açıklamada müşteri kodu (92).
        // 3 karakterden kısa kodlar atlanır: "AS" gibi bir kod her açıklamada geçer.
        foreach ($customers as $customer) {
            $code = trim((string) $customer->code);
            if ($code === '' || mb_strlen($code) < 3) {
                continue;
            }
            if (preg_match('/\b'.preg_quote($this->normalize($code), '/').'\b/u', $haystack) === 1) {
                return $this->result($customer->id, 92, "Açıklamada müşteri kodu ({$code})");
            }
        }

        // 3) Açıklamada VKN/TCKN (90).
        // tax_number 'encrypted' cast'li olduğu için SQL'de ARANAMAZ; karşılaştırma
        // bellekte yapılır. Bu, şifreli hassas veri saklamanın bilinen bedelidir.
        $digits = $this->extractLongNumbers($line->description);
        if ($digits !== []) {
            foreach ($customers as $customer) {
                $taxNumber = $this->safeTaxNumber($customer);
                if ($taxNumber !== null && in_array($taxNumber, $digits, true)) {
                    return $this->result($customer->id, 90, 'Açıklamada vergi/TC numarası');
                }
            }
        }

        // 4-5) Unvan: tam geçiş (85) ya da bulanık benzerlik (60-80).
        $best = ['customer_id' => null, 'confidence' => 0, 'reason' => null];

        foreach ($customers as $customer) {
            $name = $this->normalize((string) $customer->name);
            if ($name === '' || mb_strlen($name) < 5) {
                continue;
            }

            if (str_contains($haystack, $name)) {
                return $this->result($customer->id, 85, 'Açıklamada müşteri unvanı');
            }

            $score = $this->similarity($name, $haystack);
            if ($score > $best['confidence']) {
                $best = [
                    'customer_id' => $customer->id,
                    'confidence' => $score,
                    'reason' => 'Unvan benzerliği (%'.$score.')',
                ];
            }
        }

        // 60 altı "eşleşme yok" sayılır: zayıf bir tahmin göstermek,
        // kullanıcıyı yanlış onaya yönlendirir.
        return $best['confidence'] >= 60
            ? $this->result($best['customer_id'], $best['confidence'], $best['reason'])
            : $this->result(null, 0, null);
    }

    /**
     * Unvan benzerliği: kelime bazlı örtüşme.
     * "OZ KARDESLER OTOMOTIV" ile "OZKARDESLER OTO SAN TIC" gibi varyasyonları yakalar.
     *
     * Şirket eki olan kelimeler (ltd, sti, san, tic…) elenir; aksi halde
     * BÜTÜN şirketler birbirine benzer çıkardı.
     */
    private function similarity(string $name, string $haystack): int
    {
        $nameWords = array_filter(
            explode(' ', $name),
            fn (string $w) => mb_strlen($w) >= 4
                && ! in_array($w, ['ltd', 'sti', 'san', 'tic', 'ins', 'ith', 'ihr'], true)
        );

        if ($nameWords === []) {
            return 0;
        }

        $hits = 0;
        foreach ($nameWords as $word) {
            if (str_contains($haystack, $word)) {
                $hits++;
            }
        }

        if ($hits === 0) {
            return 0;
        }

        $coverage = (int) round(($hits / count($nameWords)) * 100);

        // 80 ile sınırlanır: tüm anlamlı kelimeler geçse bile bu bir TAHMİNDİR,
        // otomatik eşleşme eşiğini (85) asla kendi başına aşamaz.
        return min(80, $coverage);
    }

    /** @return Collection<int, Customer> */
    private function customers(): Collection
    {
        if ($this->customers === null) {
            $this->customers = Customer::query()
                ->where('is_active', true)
                ->get(['id', 'code', 'name', 'iban', 'tax_number', 'tckn']);
        }

        return $this->customers;
    }

    /**
     * Şifreleme anahtarı değişmiş ya da kayıt bozuksa okuma DecryptException
     * fırlatır. Tek bozuk kayıt YÜZLERCE satırlık mutabakatı çökertmemeli;
     * o müşteri sessizce atlanır.
     */
    private function safeTaxNumber(Customer $customer): ?string
    {
        $value = rescue(fn () => $customer->tax_number, null, report: false);

        $clean = preg_replace('/\D/', '', (string) $value) ?? '';

        return $clean !== '' ? $clean : null;
    }

    /** @return list<string> açıklamadaki 10-11 haneli sayılar (VKN/TCKN adayları) */
    private function extractLongNumbers(string $text): array
    {
        preg_match_all('/\b\d{10,11}\b/u', $text, $m);

        return array_values(array_unique($m[0]));
    }

    /**
     * Türkçe karakter katlama.
     * Banka açıklamaları çoğu zaman ASCII'ye düşürülmüş gelir ("ŞAHİN" → "SAHIN");
     * karşılaştırmanın iki tarafı da aynı normalleştirmeden geçmeli.
     */
    private function normalize(string $value): string
    {
        $tr = [
            'İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ş' => 's', 'ş' => 's',
            'Ğ' => 'g', 'ğ' => 'g', 'Ü' => 'u', 'ü' => 'u', 'Ö' => 'o',
            'ö' => 'o', 'Ç' => 'c', 'ç' => 'c', 'Â' => 'a', 'â' => 'a',
        ];

        $value = strtr($value, $tr);
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    /** @return array{customer_id: int|null, confidence: int, reason: string|null} */
    private function result(?int $customerId, int $confidence, ?string $reason): array
    {
        return ['customer_id' => $customerId, 'confidence' => $confidence, 'reason' => $reason];
    }
}
