export default function TermsOfService(): JSX.Element {
  return (
    <div className="prose prose-sm max-w-none text-gray-700">
      <h1 className="text-xl font-bold text-gray-900 mb-4">SurveyMate 利用規約</h1>
      <p className="text-sm text-gray-500 mb-6">制定日: 2024年12月13日</p>

      <section className="mb-6 p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
        <h2 className="text-base font-semibold text-yellow-800 mb-2">重要なお知らせ</h2>
        <p className="text-sm leading-relaxed text-yellow-700">
          本利用規約は，専門家による法的助言に基づいて作成されたものではありません．
          本規約の内容が法的に十分でない可能性，または適用される法令により一部が無効となる可能性があることをご了承ください．
          本規約に関して疑問がある場合は，専門家にご相談ください．
        </p>
      </section>

      <section className="mb-6">
        <h2 className="text-base font-semibold text-gray-800 mb-2">第1条（サービスの概要）</h2>
        <p className="text-sm leading-relaxed">
          SurveyMate（以下「本サービス」）は，学術論文誌のRSSフィードから論文情報を自動収集し，AI（人工知能）による構造化要約を生成するWebアプリケーションです．
        </p>
      </section>

      <section className="mb-6">
        <h2 className="text-base font-semibold text-gray-800 mb-2">第2条（研究開発段階のシステム）</h2>
        <ol className="list-decimal list-inside text-sm leading-relaxed space-y-1">
          <li>本サービスは，研究開発途上にあるパイロットシステムとして運用されています．</li>
          <li>予告なく仕様変更，機能追加・削除，データのリセット，サービスの一時停止または終了を行う場合があります．</li>
          <li>本サービスは「現状有姿（AS IS）」で提供され，明示・黙示を問わず，商品性，特定目的への適合性，権利非侵害等について，いかなる保証も行いません．</li>
        </ol>
      </section>

      <section className="mb-6">
        <h2 className="text-base font-semibold text-gray-800 mb-2">第3条（料金）</h2>
        <ol className="list-decimal list-inside text-sm leading-relaxed space-y-1">
          <li>本サービスは現在無料で提供されていますが，将来予告なく有料化する場合があります．</li>
          <li>有料化の時期，料金体系，課金方法等については，運営者の裁量により決定されます．</li>
          <li>有料化に際し，ユーザーには継続利用の選択肢が与えられ，有料化に同意しない場合はサービスの利用を停止することができます．</li>
        </ol>
      </section>

      <section className="mb-6">
        <h2 className="text-base font-semibold text-gray-800 mb-2">第4条（AI生成コンテンツに関する注意）</h2>
        <ol className="list-decimal list-inside text-sm leading-relaxed space-y-1">
          <li>本サービスで生成される要約は，生成AI（Claude，OpenAI等）によって自動生成されます．</li>
          <li>生成AIの出力には，事実と異なる情報（ハルシネーション），誤訳，不正確な解釈が含まれる可能性があります．</li>
          <li>AI生成コンテンツは参考情報としてのみご利用ください．重要な判断を行う際は，必ず原論文をご確認ください．</li>
          <li>AI生成コンテンツの正確性，完全性，有用性について，運営者は一切保証しません．</li>
          <li>AI生成コンテンツを利用して行った判断や行動の結果について，運営者は一切責任を負いません．</li>
        </ol>
      </section>

      <section className="mb-6">
        <h2 className="text-base font-semibold text-gray-800 mb-2">第5条（免責事項および責任の制限）</h2>
        <ol className="list-decimal list-inside text-sm leading-relaxed space-y-1">
          <li>法令で許容される最大限の範囲において，本サービスの利用により生じたいかなる損害（直接的，間接的，偶発的，特別，懲罰的，結果的損害を含むがこれらに限られない）についても，運営者は一切の責任を負いません．</li>
          <li>
            法令で許容される最大限の範囲において，以下の事項について運営者は責任を負いません：
            <ul className="list-disc list-inside ml-4 mt-1 space-y-0.5">
              <li>サービスの中断，遅延，停止，エラー</li>
              <li>データの消失，破損，復旧不能</li>
              <li>第三者による不正アクセス，ハッキング，ウイルス感染</li>
              <li>AI生成コンテンツの誤りに起因する損害</li>
              <li>ユーザー間のトラブル</li>
              <li>その他本サービスに関連して生じた一切の損害</li>
            </ul>
          </li>
          <li>法令により運営者の責任が認められる場合であっても，その責任は直接かつ現実に生じた通常の損害に限られ，逸失利益，機会損失，データ損失等の間接損害は含まれません．</li>
        </ol>
      </section>

      <section className="mb-6">
        <h2 className="text-base font-semibold text-gray-800 mb-2">第6条（補償）</h2>
        <p className="text-sm leading-relaxed">
          ユーザーは，自己の本規約違反または本サービスの利用に関連して，第三者から運営者に対して請求，訴訟，その他の法的措置がなされた場合，運営者を防御し，運営者が被った一切の損害（合理的な弁護士費用を含む）を補償するものとします．
        </p>
      </section>

      <section className="mb-6">
        <h2 className="text-base font-semibold text-gray-800 mb-2">第7条（データの取り扱い）</h2>
        <ol className="list-decimal list-inside text-sm leading-relaxed space-y-1">
          <li>本サービスで収集されるユーザーの利用データ（操作履歴，設定情報，生成された要約等）は，匿名化した上で研究目的に使用される場合があります．</li>
          <li>研究成果は学術論文，学会発表等で公開される可能性があります．</li>
          <li>個人を特定できる情報が公開されることはありません．</li>
          <li>パスワードは暗号化して保存され，運営者を含む誰も閲覧することはできません．</li>
          <li>ユーザーは，本サービス上のデータについて定期的にバックアップを取ることを推奨します．データ消失に対する責任は負いかねます．</li>
        </ol>
      </section>

      <section className="mb-6">
        <h2 className="text-base font-semibold text-gray-800 mb-2">第8条（禁止事項）</h2>
        <p className="text-sm leading-relaxed mb-2">ユーザーは以下の行為を行ってはなりません：</p>
        <ol className="list-decimal list-inside text-sm leading-relaxed space-y-1">
          <li>法令または公序良俗に違反する行為</li>
          <li>本サービスのサーバーに過度の負荷をかける行為（自動化ツール，スクレイピング等を含む）</li>
          <li>本サービスの運営を妨害する行為</li>
          <li>他のユーザーになりすます行為</li>
          <li>本サービスを商業目的で利用する行為（事前の書面による許可がない場合）</li>
          <li>本サービスのセキュリティを侵害または迂回しようとする行為</li>
          <li>本サービスのリバースエンジニアリング，逆コンパイル，逆アセンブル</li>
          <li>その他，運営者が合理的に不適切と判断する行為</li>
        </ol>
      </section>

      <section className="mb-6">
        <h2 className="text-base font-semibold text-gray-800 mb-2">第9条（アカウントの停止・削除）</h2>
        <ol className="list-decimal list-inside text-sm leading-relaxed space-y-1">
          <li>運営者は，ユーザーが本規約に違反した場合，または違反のおそれがあると合理的に判断した場合，事前の通知なくアカウントを停止または削除できます．</li>
          <li>アカウント停止・削除によりユーザーに生じた損害について，運営者は一切責任を負いません．</li>
        </ol>
      </section>

      <section className="mb-6">
        <h2 className="text-base font-semibold text-gray-800 mb-2">第10条（知的財産権）</h2>
        <ol className="list-decimal list-inside text-sm leading-relaxed space-y-1">
          <li>本サービスに関する知的財産権は，運営者または正当な権利者に帰属します．</li>
          <li>ユーザーが入力した研究分野，観点等の情報の著作権はユーザーに帰属します．</li>
          <li>論文情報の著作権は各論文の著者・出版社に帰属します．</li>
          <li>ユーザーは，本サービスの利用により運営者の知的財産権のライセンスを取得するものではありません．</li>
        </ol>
      </section>

      <section className="mb-6">
        <h2 className="text-base font-semibold text-gray-800 mb-2">第11条（サービスの変更・終了）</h2>
        <ol className="list-decimal list-inside text-sm leading-relaxed space-y-1">
          <li>運営者は，自己の裁量により，事前の通知なく本サービスの内容を変更，または提供を終了できます．</li>
          <li>サービス終了時，ユーザーのデータは削除されます．事前のバックアップはユーザーの責任で行ってください．</li>
          <li>サービスの変更または終了によりユーザーに生じた損害について，運営者は一切責任を負いません．</li>
        </ol>
      </section>

      <section className="mb-6">
        <h2 className="text-base font-semibold text-gray-800 mb-2">第12条（利用規約の変更）</h2>
        <ol className="list-decimal list-inside text-sm leading-relaxed space-y-1">
          <li>運営者は，自己の裁量により，随時本規約を変更することができます．</li>
          <li>変更後の規約は，本サービス上での掲示をもって効力を生じます．</li>
          <li>変更後に本サービスを利用した場合，変更後の規約に同意したものとみなします．</li>
          <li>重要な変更がある場合は，合理的な方法で通知するよう努めますが，通知の欠如により規約変更の効力が影響を受けることはありません．</li>
        </ol>
      </section>

      <section className="mb-6">
        <h2 className="text-base font-semibold text-gray-800 mb-2">第13条（可分性）</h2>
        <p className="text-sm leading-relaxed">
          本規約のいずれかの条項が管轄裁判所により無効または執行不能と判断された場合でも，当該条項は法令で認められる最大限の範囲で効力を有し，本規約の他の条項の有効性および執行可能性には影響しないものとします．
        </p>
      </section>

      <section className="mb-6">
        <h2 className="text-base font-semibold text-gray-800 mb-2">第14条（権利の不放棄）</h2>
        <p className="text-sm leading-relaxed">
          運営者が本規約に基づく権利を行使しない，または行使を遅延したとしても，当該権利の放棄とはみなされません．
        </p>
      </section>

      <section className="mb-6">
        <h2 className="text-base font-semibold text-gray-800 mb-2">第15条（準拠法）</h2>
        <p className="text-sm leading-relaxed">
          本規約は日本法に準拠し，解釈されるものとします．
        </p>
      </section>

      <section className="mb-6">
        <h2 className="text-base font-semibold text-gray-800 mb-2">第16条（同意）</h2>
        <ol className="list-decimal list-inside text-sm leading-relaxed space-y-1">
          <li>本サービスへの登録ボタンを押下することをもって，ユーザーは本規約のすべての条項を読み，理解し，同意したものとみなします．</li>
          <li>本規約に同意いただけない場合は，本サービスを利用することはできません．</li>
        </ol>
      </section>

      <section className="mb-4 p-3 bg-gray-50 border border-gray-200 rounded-lg">
        <p className="text-xs text-gray-500 text-center">
          以上
        </p>
      </section>
    </div>
  );
}
