import { Form, Head } from '@inertiajs/react';
import { Save } from 'lucide-react';

import { Page, PageTitle, SectionCard } from '@/components/admin/page';
import {
    SelectField,
    SwitchField,
    TextField,
} from '@/components/shop/form-fields';
import { Button } from '@/components/ui/button';
import { FieldGroup } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { t } from '@/lib/i18n';
import { edit, update } from '@/routes/panel/iyzico/settings';

type Props = {
    settings: {
        enabled: boolean;
        has_api_key: boolean;
        has_secret_key: boolean;
        sandbox: boolean;
        max_installment: number;
        fee_payer: string;
    };
};

const INSTALLMENTS = [
    { value: '1', label: t('Taksit yok (tek çekim)') },
    ...[2, 3, 6, 9, 12].map((n) => ({
        value: String(n),
        label: t('En fazla :n taksit', { n }),
    })),
];

export default function IyzicoSettings({ settings }: Props) {
    return (
        <>
            <Head title="iyzico" />

            <Page embedded>
                <PageTitle
                    compact
                    title="iyzico"
                    description={t(
                        'Kredi / banka kartıyla ödeme (iyzico Checkout Form, 3D Secure). Siparişlerde ve bayinin Cari Hesabım ekranındaki kartla cari ödemede kullanılır.',
                    )}
                />

                <Form
                    {...update.form()}
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <SectionCard title={t('API bilgileri')}>
                                <FieldGroup>
                                    <SwitchField
                                        name="enabled"
                                        label={t(
                                            'iyzico ile kartla ödemeyi aç',
                                        )}
                                        description={t(
                                            'Açık ve API anahtarları girilmişse ödeme ekranında sunulur.',
                                        )}
                                        defaultChecked={settings.enabled}
                                    />
                                    <TextField
                                        name="api_key"
                                        label={t('API anahtarı')}
                                        type="password"
                                        placeholder={
                                            settings.has_api_key
                                                ? t(
                                                      '•••••••• (kayıtlı; değiştirmek için yazın)',
                                                  )
                                                : ''
                                        }
                                        error={errors.api_key}
                                    />
                                    <TextField
                                        name="secret_key"
                                        label={t('Gizli anahtar')}
                                        type="password"
                                        placeholder={
                                            settings.has_secret_key
                                                ? t(
                                                      '•••••••• (kayıtlı; değiştirmek için yazın)',
                                                  )
                                                : ''
                                        }
                                        error={errors.secret_key}
                                    />
                                    <SwitchField
                                        name="sandbox"
                                        label={t('Sandbox (test ortamı)')}
                                        description={t(
                                            'iyzico sandbox hesabının anahtarlarıyla deneme ödemesi alınır; canlıya geçince kapatın.',
                                        )}
                                        defaultChecked={settings.sandbox}
                                    />
                                </FieldGroup>
                            </SectionCard>

                            <SectionCard title={t('Taksit')}>
                                <FieldGroup>
                                    <SelectField
                                        name="max_installment"
                                        label={t('Taksit')}
                                        options={INSTALLMENTS}
                                        defaultValue={String(
                                            settings.max_installment,
                                        )}
                                        error={errors.max_installment}
                                    />
                                    <SelectField
                                        name="fee_payer"
                                        label={t('Taksit farkını kim öder')}
                                        options={[
                                            {
                                                value: 'customer',
                                                label: t(
                                                    'Müşteri (vade farkı ödeme tutarına eklenir)',
                                                ),
                                            },
                                            {
                                                value: 'merchant',
                                                label: t(
                                                    'Firma (müşteri sipariş tutarını öder)',
                                                ),
                                            },
                                        ]}
                                        defaultValue={settings.fee_payer}
                                        error={errors.fee_payer}
                                        description={t(
                                            'iyzico panelinizdeki taksit ayarıyla aynı olmalıdır; ödeme ekranındaki taksit tablosu buna göre gösterilir.',
                                        )}
                                    />
                                </FieldGroup>
                            </SectionCard>

                            <div className="flex justify-end">
                                <Button type="submit" disabled={processing}>
                                    {processing ? (
                                        <Spinner data-icon="inline-start" />
                                    ) : (
                                        <Save data-icon="inline-start" />
                                    )}
                                    {t('Kaydet')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </Page>
        </>
    );
}

IyzicoSettings.layout = {
    breadcrumbs: [{ title: 'iyzico', href: edit() }],
};
