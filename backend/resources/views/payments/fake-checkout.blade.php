{{-- Page du prestataire simulé (FakeCheckoutController) : le joueur y confirme ou refuse le paiement, comme chez MonCash. --}}
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $label }} — confirmation du paiement</title>
    <style nonce="{{ $nonce }}">
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: #eef1f5; color: #1c2330; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { width: 100%; max-width: 400px; background: #fff; border-radius: 14px; box-shadow: 0 8px 30px rgba(20, 30, 50, .12); overflow: hidden; }
        header { background: #24324a; color: #fff; padding: 18px 22px; }
        header strong { display: block; font-size: 1.1rem; }
        header span { opacity: .8; font-size: .85rem; }
        section { padding: 22px; }
        dl { margin: 0 0 20px; display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; }
        dt { color: #5b6577; }
        dd { margin: 0; text-align: right; font-weight: 600; word-break: break-all; }
        .amount { font-size: 1.8rem; font-weight: 700; text-align: center; margin: 0 0 18px; }
        form { display: grid; gap: 10px; }
        button, a.button { display: block; width: 100%; padding: 13px; border: 0; border-radius: 10px; font: inherit; font-weight: 600; text-align: center; text-decoration: none; cursor: pointer; }
        .pay { background: #1f8f4e; color: #fff; }
        .decline { background: #f1f3f6; color: #a12a2a; }
        a.button { background: #24324a; color: #fff; }
        .status { padding: 12px; border-radius: 10px; background: #f1f3f6; margin: 0 0 16px; text-align: center; }
        footer { padding: 0 22px 18px; color: #5b6577; font-size: .8rem; text-align: center; }
    </style>
</head>
<body>
<main>
    <header>
        <strong>{{ $label }}</strong>
        <span>Paiement à REYEL 16 PARYAJ</span>
    </header>
    <section>
        <p class="amount">{{ $amount }}</p>
        <dl>
            <dt>Bénéficiaire</dt><dd>REYEL 16 PARYAJ</dd>
            <dt>Référence</dt><dd>{{ $transaction->reference }}</dd>
        </dl>

        @if ($transaction->status === \App\Modules\Payments\Enums\PaymentStatus::Pending)
            <form method="post" action="{{ $action }}">
                <button class="pay" type="submit" name="decision" value="approve">Payer {{ $amount }}</button>
                <button class="decline" type="submit" name="decision" value="decline">Refuser le paiement</button>
            </form>
        @else
            <p class="status">{{ $statusText }}</p>
            <a class="button" href="{{ $returnUrl }}">Retour à REYEL 16 PARYAJ</a>
        @endif
    </section>
    <footer>Prestataire simulé : aucun argent réel n'est débité.</footer>
</main>
</body>
</html>
