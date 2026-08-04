/**
 * Public Privacy Policy (PDPA ss.11-12). Static content - no data fetch. The
 * bracketed values are placeholders for the business/legal team to complete
 * before launch; the page structure and the DPO contact block are the fixed
 * requirement.
 */
export default function PrivacyPolicyPage() {
  return (
    <article className="mx-auto max-w-content px-4 py-10 sm:px-6">
      <h1 className="font-display text-3xl text-fg">Privacy Policy</h1>
      <p className="mt-2 text-sm text-fg-muted">Last updated: [DATE]</p>

      <div className="mt-8 flex flex-col gap-6 text-sm leading-relaxed text-fg-muted">
        <section>
          <h2 className="mb-2 font-display text-xl text-fg">Who we are</h2>
          <p>
            This policy explains how [COMPANY LEGAL NAME] ("we") collects, uses,
            discloses, and protects personal data in line with Singapore's
            Personal Data Protection Act (PDPA).
          </p>
        </section>

        <section>
          <h2 className="mb-2 font-display text-xl text-fg">What we collect</h2>
          <p>
            Account details you provide at registration (name, work email,
            phone, company), and the delivery details you enter at checkout for
            each order recipient (name, phone, address).
          </p>
        </section>

        <section>
          <h2 className="mb-2 font-display text-xl text-fg">Why we collect it</h2>
          <p>
            To create and manage your account, prepare and fulfil your orders,
            arrange delivery through our courier partner, and communicate with
            you about your orders.
          </p>
        </section>

        <section>
          <h2 className="mb-2 font-display text-xl text-fg">Who we share it with</h2>
          <p>
            Delivery details are shared with our courier partner solely to
            deliver your order. We do not sell personal data.
          </p>
        </section>

        <section>
          <h2 className="mb-2 font-display text-xl text-fg">How long we keep it</h2>
          <p>
            We retain personal data only as long as needed for the purposes above
            or as required by law. [RETENTION SUMMARY - to be confirmed with legal.]
          </p>
        </section>

        <section>
          <h2 className="mb-2 font-display text-xl text-fg">Your rights</h2>
          <p>
            You may request access to, or correction of, your personal data, and
            you may withdraw consent, by contacting our Data Protection Officer.
          </p>
        </section>

        <section>
          <h2 className="mb-2 font-display text-xl text-fg">Data Protection Officer</h2>
          <p>
            [DPO NAME / ROLE]
            <br />
            Email: [DPO EMAIL]
            <br />
            [COMPANY LEGAL NAME], [REGISTERED ADDRESS]
          </p>
        </section>
      </div>
    </article>
  );
}
