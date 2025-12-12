import { useState, useEffect, useRef, useCallback } from 'react';
import { ExternalLink, Sparkles, ChevronUp, ChevronDown, Loader2, Tag, Plus, X, MessageCircle, Send, Trash2, Maximize2, Minimize2, FileText } from 'lucide-react';
import api from '../api';
import type { Paper, Summary, Tag as TagType, ChatMessage } from '../types';

interface PaperCardProps {
  paper: Paper;
  selectedProvider: string;
  onTagsChange?: () => void;
}

export default function PaperCard({ paper, selectedProvider, onTagsChange }: PaperCardProps): JSX.Element {
  const [summary, setSummary] = useState<Summary | null>(null);
  const [loading, setLoading] = useState(false);
  const [expanded, setExpanded] = useState(false);
  const [abstractExpanded, setAbstractExpanded] = useState(false);

  // タグ関連state
  const [tags, setTags] = useState<TagType[]>(paper.tags || []);
  const [showTagInput, setShowTagInput] = useState(false);
  const [tagInput, setTagInput] = useState('');
  const [addingTag, setAddingTag] = useState(false);
  const tagInputRef = useRef<HTMLInputElement>(null);

  // タグ候補（オートコンプリート）
  const [allTags, setAllTags] = useState<TagType[]>([]);
  const [selectedSuggestionIndex, setSelectedSuggestionIndex] = useState(-1);
  const suggestionsRef = useRef<HTMLDivElement>(null);
  const tagDropdownRef = useRef<HTMLDivElement>(null);

  // チャット関連state
  const [chatOpen, setChatOpen] = useState(false);
  const [chatFloating, setChatFloating] = useState(false);
  const [chatMessages, setChatMessages] = useState<ChatMessage[]>([]);
  const [chatInput, setChatInput] = useState('');
  const [chatLoading, setChatLoading] = useState(false);
  const [chatFetching, setChatFetching] = useState(false);
  const chatMessagesRef = useRef<HTMLDivElement>(null);
  const chatFloatingMessagesRef = useRef<HTMLDivElement>(null);
  const chatInputRef = useRef<HTMLTextAreaElement>(null);
  const summaryRef = useRef<HTMLDivElement>(null);

  // 本文表示関連state
  const [fullTextOpen, setFullTextOpen] = useState(false);
  const [fullText, setFullText] = useState<string | null>(null);
  const [fullTextLoading, setFullTextLoading] = useState(false);
  const [fullTextSource, setFullTextSource] = useState<string | null>(null);

  // 外側クリックでドロップダウンを閉じる
  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (tagDropdownRef.current && !tagDropdownRef.current.contains(event.target as Node)) {
        setShowTagInput(false);
        setTagInput('');
      }
    };
    if (showTagInput) {
      document.addEventListener('mousedown', handleClickOutside);
    }
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, [showTagInput]);

  // 既存の要約がある場合は初期化時にセット
  useEffect(() => {
    if (paper.summaries && paper.summaries.length > 0) {
      // 最新の要約を使用
      setSummary(paper.summaries[0]);
    }
  }, [paper.summaries]);

  const hasSummary = summary !== null || (paper.summaries && paper.summaries.length > 0);

  const formatDate = (dateString: string | null): string => {
    if (!dateString) return '';
    const date = new Date(dateString);
    return `${date.getFullYear()}年${date.getMonth() + 1}月${date.getDate()}日`;
  };

  const generateSummary = async (): Promise<void> => {
    setLoading(true);
    try {
      const data = await api.summaries.generate(paper.id, selectedProvider);
      if (data.success) {
        setSummary(data.summary);
        setExpanded(true);
      } else {
        throw new Error('Summary generation failed');
      }
    } catch (error) {
      alert('要約の生成に失敗しました: ' + (error as Error).message);
    } finally {
      setLoading(false);
    }
  };

  const authors = Array.isArray(paper.authors) ? paper.authors.join(', ') : paper.authors;

  // タグ追加
  const handleAddTag = async (): Promise<void> => {
    if (!tagInput.trim()) return;

    setAddingTag(true);
    try {
      const data = await api.tags.addToPaper(paper.id, { tag_name: tagInput.trim() });
      if (data.success) {
        setTags((prev) => [...prev, data.tag]);
        setTagInput('');
        setShowTagInput(false);
        onTagsChange?.();
      }
    } catch (error) {
      console.error('Failed to add tag:', error);
    } finally {
      setAddingTag(false);
    }
  };

  // タグ削除
  const handleRemoveTag = async (tagId: number): Promise<void> => {
    try {
      await api.tags.removeFromPaper(paper.id, tagId);
      setTags((prev) => prev.filter((t) => t.id !== tagId));
      onTagsChange?.();
    } catch (error) {
      console.error('Failed to remove tag:', error);
    }
  };

  // タグ一覧を取得
  const fetchAllTags = useCallback(async (): Promise<void> => {
    try {
      const data = await api.tags.list();
      if (data.success) {
        setAllTags(data.tags);
      }
    } catch (error) {
      console.error('Failed to fetch tags:', error);
    }
  }, []);

  // タグ入力欄が表示されたらタグ一覧を取得
  useEffect(() => {
    if (showTagInput) {
      fetchAllTags();
      tagInputRef.current?.focus();
    }
  }, [showTagInput, fetchAllTags]);

  // 入力に基づいてフィルタされたタグ候補
  const filteredSuggestions = allTags.filter((tag) => {
    // 既に付いているタグは除外
    if (tags.some((t) => t.id === tag.id)) return false;
    // 入力がない場合はすべて表示
    if (!tagInput.trim()) return true;
    // 入力にマッチするタグを表示
    return tag.name.toLowerCase().includes(tagInput.toLowerCase());
  });

  // 候補を選択してタグを追加
  const selectSuggestion = async (tag: TagType): Promise<void> => {
    setAddingTag(true);
    try {
      const data = await api.tags.addToPaper(paper.id, { tag_id: tag.id });
      if (data.success) {
        setTags((prev) => [...prev, data.tag]);
        setTagInput('');
        setShowTagInput(false);
        onTagsChange?.();
      }
    } catch (error) {
      console.error('Failed to add tag:', error);
    } finally {
      setAddingTag(false);
    }
  };

  // 入力変更時
  const handleTagInputChange = (value: string): void => {
    setTagInput(value);
    setSelectedSuggestionIndex(-1);
  };

  // アブストラクトが長いかどうかを判定（約150文字以上）
  const isAbstractLong = paper.abstract && paper.abstract.length > 150;

  // チャットメッセージ取得
  const fetchChatMessages = useCallback(async (summaryId: number): Promise<void> => {
    setChatFetching(true);
    try {
      const data = await api.summaries.chat.getMessages(summaryId);
      if (data.success) {
        setChatMessages(data.messages);
      }
    } catch (error) {
      console.error('Failed to fetch chat messages:', error);
    } finally {
      setChatFetching(false);
    }
  }, []);

  // チャットを開いたときにメッセージを取得
  useEffect(() => {
    if (chatOpen && summary) {
      fetchChatMessages(summary.id);
    }
  }, [chatOpen, summary, fetchChatMessages]);

  // チャットメッセージ送信
  const sendChatMessage = async (): Promise<void> => {
    if (!chatInput.trim() || !summary || chatLoading) return;

    const message = chatInput.trim();
    setChatInput('');

    // ユーザーメッセージを即座に表示（仮ID使用）
    const tempUserMessage: ChatMessage = {
      id: Date.now(),
      role: 'user',
      content: message,
      created_at: new Date().toISOString(),
    };
    setChatMessages((prev) => [...prev, tempUserMessage]);
    setChatLoading(true);

    try {
      const data = await api.summaries.chat.send(summary.id, message);
      if (data.success) {
        // 仮のユーザーメッセージを正式なものに置き換え、AI応答を追加
        setChatMessages((prev) => [
          ...prev.filter((msg) => msg.id !== tempUserMessage.id),
          data.user_message,
          data.ai_message,
        ]);
      }
    } catch (error) {
      console.error('Failed to send chat message:', error);
      alert('メッセージの送信に失敗しました: ' + (error as Error).message);
      // 失敗時は仮メッセージを削除し、入力を復元
      setChatMessages((prev) => prev.filter((msg) => msg.id !== tempUserMessage.id));
      setChatInput(message);
    } finally {
      setChatLoading(false);
    }
  };

  // チャット履歴クリア
  const clearChat = async (): Promise<void> => {
    if (!summary || !confirm('チャット履歴をクリアしますか？')) return;

    try {
      const data = await api.summaries.chat.clear(summary.id);
      if (data.success) {
        setChatMessages([]);
      }
    } catch (error) {
      console.error('Failed to clear chat:', error);
    }
  };

  // チャットメッセージ追加時にコンテナ内のみスクロール（ページ全体は動かさない）
  useEffect(() => {
    if (chatMessagesRef.current) {
      chatMessagesRef.current.scrollTop = chatMessagesRef.current.scrollHeight;
    }
    if (chatFloatingMessagesRef.current) {
      chatFloatingMessagesRef.current.scrollTop = chatFloatingMessagesRef.current.scrollHeight;
    }
  }, [chatMessages]);

  // フローティングウィンドウを開く
  const openFloatingChat = (): void => {
    setChatFloating(true);
    // フローティング時はbodyのスクロールを無効化
    document.body.style.overflow = 'hidden';
  };

  // フローティングウィンドウを閉じる
  const closeFloatingChat = (): void => {
    setChatFloating(false);
    document.body.style.overflow = '';
  };

  // ESCキーでフローティングを閉じる
  useEffect(() => {
    const handleEsc = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && chatFloating) {
        closeFloatingChat();
      }
    };
    document.addEventListener('keydown', handleEsc);
    return () => document.removeEventListener('keydown', handleEsc);
  }, [chatFloating]);

  // テキストエリアの高さ自動調整
  const handleChatInputChange = (e: React.ChangeEvent<HTMLTextAreaElement>): void => {
    setChatInput(e.target.value);
    e.target.style.height = 'auto';
    e.target.style.height = Math.min(e.target.scrollHeight, 120) + 'px';
  };

  // チャットを開く/閉じる（開くときは要約の先頭にスクロール）
  const toggleChat = (): void => {
    const willOpen = !chatOpen;
    setChatOpen(willOpen);
    if (willOpen && summaryRef.current) {
      setTimeout(() => {
        summaryRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }, 100);
    }
  };

  // 本文を取得して表示
  const openFullText = async (): Promise<void> => {
    if (fullText) {
      setFullTextOpen(true);
      document.body.style.overflow = 'hidden';
      return;
    }

    setFullTextLoading(true);
    try {
      const data = await api.papers.getFullText(paper.id);
      if (data.success) {
        setFullText(data.full_text);
        setFullTextSource(data.full_text_source);
        setFullTextOpen(true);
        document.body.style.overflow = 'hidden';
      }
    } catch (error) {
      alert('本文の取得に失敗しました: ' + (error as Error).message);
    } finally {
      setFullTextLoading(false);
    }
  };

  // 本文モーダルを閉じる
  const closeFullText = (): void => {
    setFullTextOpen(false);
    document.body.style.overflow = '';
  };

  // ESCキーで本文モーダルを閉じる
  useEffect(() => {
    const handleEsc = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && fullTextOpen) {
        closeFullText();
      }
    };
    document.addEventListener('keydown', handleEsc);
    return () => document.removeEventListener('keydown', handleEsc);
  }, [fullTextOpen]);

  return (
    <div className="bg-white rounded-xl shadow-sm border border-gray-200 hover:shadow-md transition-all">
      <div className="p-3 sm:p-5">
        <div className="flex items-start gap-2 sm:gap-4">
          {/* 色バー */}
          <div className={`w-1 sm:w-1.5 self-stretch rounded-full ${paper.journal_color}`} />

          {/* コンテンツ */}
          <div className="flex-1 min-w-0">
            {/* ヘッダー行: メタ情報（左）とタグ（右） */}
            <div className="flex items-start justify-between gap-2 mb-2">
              {/* メタ情報（左側） */}
              <div className="flex items-center gap-2 flex-wrap">
                <span className={`px-2 sm:px-2.5 py-0.5 sm:py-1 text-xs font-medium text-white rounded-lg ${paper.journal_color} leading-tight`}>
                  {paper.journal_name}
                </span>
                <span className="text-xs text-gray-500">
                  {formatDate(paper.published_date)}
                </span>
                {hasSummary && (
                  <span className="text-xs bg-green-100 text-green-700 px-2 py-0.5 rounded-full flex items-center gap-1">
                    <Sparkles className="w-3 h-3" />
                    要約済
                  </span>
                )}
              </div>

              {/* タグ（右上） */}
              <div className="flex items-center gap-1 flex-wrap justify-end relative">
                {tags.map((tag) => (
                  <span
                    key={tag.id}
                    className={`inline-flex items-center gap-1 px-2 py-0.5 text-xs rounded-full ${tag.color || 'bg-gray-500'} text-white group`}
                  >
                    <Tag className="w-3 h-3" />
                    {tag.name}
                    <button
                      onClick={() => handleRemoveTag(tag.id)}
                      className="opacity-0 group-hover:opacity-100 transition-opacity ml-0.5 hover:bg-white/20 rounded-full p-0.5"
                      title="Tagを削除"
                    >
                      <X className="w-3 h-3" />
                    </button>
                  </span>
                ))}
                {/* タグ追加ボタン/ドロップダウン */}
                <div className="relative" ref={tagDropdownRef}>
                  <button
                    onClick={() => setShowTagInput(!showTagInput)}
                    className={`inline-flex items-center gap-0.5 px-2 py-0.5 text-xs rounded-full border transition-colors ${
                      showTagInput
                        ? 'text-gray-600 bg-gray-50 border-gray-300'
                        : 'text-gray-500 hover:text-gray-600 hover:bg-gray-50 border-dashed border-gray-300 hover:border-gray-300'
                    }`}
                  >
                    <Plus className="w-3 h-3" />
                    Tag
                  </button>

                  {/* タグ選択ドロップダウン */}
                  {showTagInput && (
                    <div
                      ref={suggestionsRef}
                      className="absolute top-full right-0 mt-1 w-56 bg-white border border-gray-200 rounded-lg shadow-lg z-20"
                    >
                      {/* 検索入力 */}
                      <div className="p-2 border-b border-gray-100">
                        <input
                          ref={tagInputRef}
                          type="text"
                          value={tagInput}
                          onChange={(e) => handleTagInputChange(e.target.value)}
                          onKeyDown={(e) => {
                            if (e.key === 'ArrowDown') {
                              e.preventDefault();
                              setSelectedSuggestionIndex((prev) =>
                                prev < filteredSuggestions.length - 1 ? prev + 1 : prev
                              );
                            } else if (e.key === 'ArrowUp') {
                              e.preventDefault();
                              setSelectedSuggestionIndex((prev) => (prev > 0 ? prev - 1 : -1));
                            } else if (e.key === 'Enter') {
                              e.preventDefault();
                              if (selectedSuggestionIndex >= 0 && filteredSuggestions[selectedSuggestionIndex]) {
                                selectSuggestion(filteredSuggestions[selectedSuggestionIndex]);
                              } else if (tagInput.trim()) {
                                handleAddTag();
                              }
                            } else if (e.key === 'Escape') {
                              setShowTagInput(false);
                              setTagInput('');
                            }
                          }}
                          placeholder="Tagを検索または新規作成..."
                          className="w-full px-2 py-1.5 text-xs border border-gray-300 rounded focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                          disabled={addingTag}
                        />
                      </div>

                      {/* 既存タグ一覧 */}
                      <div className="max-h-48 overflow-y-auto">
                        {filteredSuggestions.length > 0 ? (
                          filteredSuggestions.slice(0, 10).map((tag, index) => (
                            <button
                              key={tag.id}
                              onClick={() => selectSuggestion(tag)}
                              disabled={addingTag}
                              className={`w-full px-3 py-2 text-left text-xs flex items-center gap-2 hover:bg-gray-50 transition-colors disabled:opacity-50 ${
                                index === selectedSuggestionIndex ? 'bg-gray-50' : ''
                              }`}
                            >
                              <span className={`w-2.5 h-2.5 rounded-full flex-shrink-0 ${tag.color}`} />
                              <span className="truncate flex-1">{tag.name}</span>
                              <span className="text-gray-400 text-[10px]">{tag.paper_count || 0}件</span>
                            </button>
                          ))
                        ) : (
                          <div className="px-3 py-2 text-xs text-gray-500">
                            {allTags.length === 0 ? 'Tagがありません' : '該当するTagがありません'}
                          </div>
                        )}
                      </div>

                      {/* 新規作成オプション */}
                      {tagInput.trim() && !filteredSuggestions.some(t => t.name.toLowerCase() === tagInput.toLowerCase()) && (
                        <div className="border-t border-gray-100">
                          <button
                            onClick={handleAddTag}
                            disabled={addingTag}
                            className="w-full px-3 py-2 text-left text-xs flex items-center gap-2 hover:bg-green-50 text-green-700 disabled:opacity-50"
                          >
                            {addingTag ? (
                              <Loader2 className="w-3 h-3 animate-spin" />
                            ) : (
                              <Plus className="w-3 h-3" />
                            )}
                            「{tagInput}」を新規作成
                          </button>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              </div>
            </div>

            {/* タイトル */}
            <h3 className="text-base sm:text-lg font-semibold text-gray-900 mb-2 leading-snug">
              {paper.title}
            </h3>

            {/* 著者 */}
            <p className="text-xs sm:text-sm text-gray-600 mb-3 line-clamp-2 sm:line-clamp-none">
              {authors}
            </p>

            {/* アブストラクト */}
            {paper.abstract && (
              <div className="mb-4">
                <div className="relative">
                  <div
                    className={`text-xs sm:text-sm text-gray-700 overflow-hidden transition-all duration-300 ease-in-out ${
                      !abstractExpanded && isAbstractLong ? 'max-h-[4.5em]' : 'max-h-[1000px]'
                    }`}
                  >
                    <p>{paper.abstract}</p>
                  </div>
                  {/* グラデーションオーバーレイ */}
                  {!abstractExpanded && isAbstractLong && (
                    <div className="absolute bottom-0 left-0 right-0 h-6 bg-gradient-to-t from-white to-transparent pointer-events-none" />
                  )}
                </div>
                {isAbstractLong && (
                  <button
                    onClick={() => setAbstractExpanded(!abstractExpanded)}
                    className="flex items-center gap-1 text-xs text-gray-600 hover:text-gray-800 mt-2 font-medium transition-colors"
                  >
                    {abstractExpanded ? (
                      <>
                        <ChevronUp className="w-3.5 h-3.5" />
                        概要を折りたたむ
                      </>
                    ) : (
                      <>
                        <ChevronDown className="w-3.5 h-3.5" />
                        概要を全文表示
                      </>
                    )}
                  </button>
                )}
              </div>
            )}

            {/* アクション */}
            <div className="flex items-center gap-2 sm:gap-3 flex-wrap">
              {/* AI要約ボタン：要約がない場合は生成ボタン，ある場合は表示トグル */}
              {!hasSummary ? (
                <button
                  onClick={generateSummary}
                  disabled={loading}
                  className="flex items-center gap-1.5 sm:gap-2 px-3 sm:px-4 py-1.5 sm:py-2 bg-gradient-to-r from-gray-500 to-purple-500 text-white text-xs sm:text-sm rounded-lg hover:from-gray-600 hover:to-purple-600 disabled:opacity-50 disabled:cursor-not-allowed font-medium transition-all"
                >
                  {loading ? (
                    <Loader2 className="w-3.5 h-3.5 sm:w-4 sm:h-4 animate-spin" />
                  ) : (
                    <Sparkles className="w-3.5 h-3.5 sm:w-4 sm:h-4" />
                  )}
                  {loading ? '生成中...' : 'AI要約'}
                </button>
              ) : (
                <button
                  onClick={() => setExpanded(!expanded)}
                  className="flex items-center gap-1.5 sm:gap-2 px-3 sm:px-4 py-1.5 sm:py-2 bg-gradient-to-r from-green-500 to-emerald-500 text-white text-xs sm:text-sm rounded-lg hover:from-green-600 hover:to-emerald-600 font-medium transition-all"
                >
                  {expanded ? (
                    <ChevronUp className="w-3.5 h-3.5 sm:w-4 sm:h-4" />
                  ) : (
                    <Sparkles className="w-3.5 h-3.5 sm:w-4 sm:h-4" />
                  )}
                  <span className="hidden xs:inline">{expanded ? 'AI要約を閉じる' : 'AI要約を表示'}</span>
                  <span className="xs:hidden">{expanded ? '閉じる' : '要約'}</span>
                </button>
              )}

              {/* 本文表示ボタン（本文がある場合のみ） */}
              {paper.has_full_text && (
                <button
                  onClick={openFullText}
                  disabled={fullTextLoading}
                  className="flex items-center gap-1.5 px-2 sm:px-3 py-1.5 sm:py-2 text-xs sm:text-sm text-gray-600 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors"
                >
                  {fullTextLoading ? (
                    <Loader2 className="w-3.5 h-3.5 sm:w-4 sm:h-4 animate-spin" />
                  ) : (
                    <FileText className="w-3.5 h-3.5 sm:w-4 sm:h-4" />
                  )}
                  <span className="hidden sm:inline">本文を表示</span>
                  <span className="sm:hidden">本文</span>
                </button>
              )}

              <a
                href={paper.url || '#'}
                target="_blank"
                rel="noopener noreferrer"
                className="flex items-center gap-1 px-2 sm:px-3 py-1.5 sm:py-2 text-xs sm:text-sm text-gray-600 hover:text-gray-600 transition-colors"
              >
                <ExternalLink className="w-3.5 h-3.5 sm:w-4 sm:h-4" />
                <span className="hidden sm:inline">論文を開く</span>
                <span className="sm:hidden">開く</span>
              </a>
            </div>

            {/* AI要約 */}
            {summary && expanded && (
              <div ref={summaryRef} className="mt-3 sm:mt-4 p-3 sm:p-5 bg-gradient-to-r from-gray-50 to-purple-50 rounded-xl border border-gray-100">
                <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-3 sm:mb-4">
                  <div className="flex items-center gap-2">
                    <Sparkles className="w-4 h-4 sm:w-5 sm:h-5 text-gray-600" />
                    <span className="font-medium text-gray-900 text-sm sm:text-base">AI要約</span>
                  </div>
                  <div className="flex items-center gap-2">
                    <span className="text-xs px-2 py-1 bg-gray-100 rounded-full text-gray-600">
                      {summary.ai_provider}
                    </span>
                    {summary.ai_model && (
                      <span className="text-xs text-gray-500 hidden sm:inline">
                        {summary.ai_model}
                      </span>
                    )}
                  </div>
                </div>
                <div className="space-y-3 sm:space-y-4 text-xs sm:text-sm text-gray-800">
                  {summary.purpose && (
                    <div>
                      <span className="font-semibold text-gray-700">【研究目的】</span>
                      <p className="mt-1">{summary.purpose}</p>
                    </div>
                  )}
                  {summary.methodology && (
                    <div>
                      <span className="font-semibold text-gray-700">【手法】</span>
                      <p className="mt-1">{summary.methodology}</p>
                    </div>
                  )}
                  {summary.findings && (
                    <div>
                      <span className="font-semibold text-gray-700">【主な発見】</span>
                      <p className="mt-1">{summary.findings}</p>
                    </div>
                  )}
                  {summary.implications && (
                    <div>
                      <span className="font-semibold text-gray-700">【教育への示唆】</span>
                      <p className="mt-1">{summary.implications}</p>
                    </div>
                  )}
                  {!summary.purpose && summary.summary_text && (
                    <p className="whitespace-pre-wrap">{summary.summary_text}</p>
                  )}
                </div>

                {/* チャットトグルボタン */}
                <div className="mt-4 pt-3 border-t border-gray-200">
                  <button
                    onClick={toggleChat}
                    className="flex items-center gap-2 text-sm text-gray-600 hover:text-gray-800 font-medium transition-colors"
                  >
                    <MessageCircle className="w-4 h-4" />
                    {chatOpen ? 'チャットを閉じる' : 'この要約について質問する'}
                    {chatMessages.length > 0 && !chatOpen && (
                      <span className="ml-1 px-1.5 py-0.5 text-xs bg-gray-200 text-gray-700 rounded-full">
                        {chatMessages.length}
                      </span>
                    )}
                  </button>
                </div>

                {/* チャットパネル */}
                {chatOpen && (
                  <div className="mt-3 bg-white rounded-lg border border-gray-200 overflow-hidden">
                    {/* チャットヘッダー */}
                    <div className="flex items-center justify-between px-3 py-2 bg-gray-50 border-b border-gray-200">
                      <span className="text-xs font-medium text-gray-700">
                        AIとの会話
                      </span>
                      <div className="flex items-center gap-2">
                        <button
                          onClick={openFloatingChat}
                          className="flex items-center gap-1 text-xs text-gray-500 hover:text-gray-600 transition-colors"
                          title="拡大表示"
                        >
                          <Maximize2 className="w-3 h-3" />
                        </button>
                        {chatMessages.length > 0 && (
                          <button
                            onClick={clearChat}
                            className="flex items-center gap-1 text-xs text-gray-500 hover:text-red-600 transition-colors"
                            title="履歴をクリア"
                          >
                            <Trash2 className="w-3 h-3" />
                          </button>
                        )}
                      </div>
                    </div>

                    {/* メッセージ表示エリア */}
                    <div ref={chatMessagesRef} className="max-h-64 overflow-y-auto p-3 space-y-3">
                      {chatFetching ? (
                        <div className="flex items-center justify-center py-4">
                          <Loader2 className="w-5 h-5 animate-spin text-gray-500" />
                        </div>
                      ) : chatMessages.length === 0 ? (
                        <div className="text-center py-4 text-xs text-gray-500">
                          要約について質問や追加の説明をリクエストできます
                        </div>
                      ) : (
                        chatMessages.map((msg) => (
                          <div
                            key={msg.id}
                            className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}
                          >
                            <div
                              className={`max-w-[85%] px-3 py-2 rounded-lg text-xs ${
                                msg.role === 'user'
                                  ? 'bg-gray-600 text-white'
                                  : 'bg-gray-100 text-gray-800'
                              }`}
                            >
                              <p className="whitespace-pre-wrap">{msg.content}</p>
                              {msg.role === 'assistant' && msg.ai_model && (
                                <p className="mt-1 text-[10px] opacity-60">
                                  {msg.ai_provider} / {msg.ai_model}
                                </p>
                              )}
                            </div>
                          </div>
                        ))
                      )}
                      {chatLoading && (
                        <div className="flex justify-start">
                          <div className="bg-gray-100 px-3 py-2 rounded-lg">
                            <Loader2 className="w-4 h-4 animate-spin text-gray-500" />
                          </div>
                        </div>
                      )}
                    </div>

                    {/* 入力エリア */}
                    <div className="border-t border-gray-200 p-2">
                      <div className="flex gap-2">
                        <textarea
                          ref={chatInputRef}
                          value={chatInput}
                          onChange={handleChatInputChange}
                          onKeyDown={(e) => {
                            // IME入力中は無視
                            if (e.nativeEvent.isComposing || e.keyCode === 229) return;
                            // Cmd+Enter (Mac) または Ctrl+Enter で送信
                            if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
                              e.preventDefault();
                              sendChatMessage();
                            }
                          }}
                          placeholder="質問を入力... (⌘+Enterで送信)"
                          className="flex-1 px-3 py-2 text-xs border border-gray-300 rounded-lg resize-none focus:ring-1 focus:ring-gray-500 focus:border-gray-500"
                          rows={1}
                          disabled={chatLoading}
                        />
                        <button
                          onClick={sendChatMessage}
                          disabled={!chatInput.trim() || chatLoading}
                          className="px-3 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                        >
                          {chatLoading ? (
                            <Loader2 className="w-4 h-4 animate-spin" />
                          ) : (
                            <Send className="w-4 h-4" />
                          )}
                        </button>
                      </div>
                    </div>
                  </div>
                )}
              </div>
            )}
          </div>
        </div>
      </div>

      {/* フローティングチャットモーダル */}
      {chatFloating && summary && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" onClick={closeFloatingChat}>
          <div
            className="w-full max-w-2xl h-[80vh] bg-white rounded-xl shadow-2xl flex flex-col overflow-hidden"
            onClick={(e) => e.stopPropagation()}
          >
            {/* モーダルヘッダー */}
            <div className="flex items-center justify-between px-4 py-3 bg-gradient-to-r from-gray-500 to-purple-500 text-white">
              <div className="flex-1 min-w-0 mr-4">
                <h3 className="font-medium text-sm truncate">{paper.title}</h3>
                <p className="text-xs text-white/80 truncate">AIとの会話</p>
              </div>
              <div className="flex items-center gap-2">
                {chatMessages.length > 0 && (
                  <button
                    onClick={clearChat}
                    className="p-1.5 hover:bg-white/20 rounded transition-colors"
                    title="履歴をクリア"
                  >
                    <Trash2 className="w-4 h-4" />
                  </button>
                )}
                <button
                  onClick={closeFloatingChat}
                  className="p-1.5 hover:bg-white/20 rounded transition-colors"
                  title="縮小 (ESC)"
                >
                  <Minimize2 className="w-4 h-4" />
                </button>
              </div>
            </div>

            {/* メッセージ表示エリア */}
            <div ref={chatFloatingMessagesRef} className="flex-1 overflow-y-auto p-4 space-y-4 bg-gray-50">
              {chatFetching ? (
                <div className="flex items-center justify-center py-8">
                  <Loader2 className="w-6 h-6 animate-spin text-gray-500" />
                </div>
              ) : chatMessages.length === 0 ? (
                <div className="text-center py-8 text-sm text-gray-500">
                  <MessageCircle className="w-12 h-12 mx-auto mb-3 text-gray-300" />
                  <p>要約について質問や追加の説明をリクエストできます</p>
                  <p className="text-xs mt-1 text-gray-400">例: 「この研究の限界は？」「実務への応用は？」</p>
                </div>
              ) : (
                chatMessages.map((msg) => (
                  <div
                    key={msg.id}
                    className={`flex ${msg.role === 'user' ? 'justify-end' : 'justify-start'}`}
                  >
                    <div
                      className={`max-w-[80%] px-4 py-3 rounded-2xl text-sm ${
                        msg.role === 'user'
                          ? 'bg-gray-600 text-white rounded-br-md'
                          : 'bg-white text-gray-800 shadow-sm border border-gray-200 rounded-bl-md'
                      }`}
                    >
                      <p className="whitespace-pre-wrap">{msg.content}</p>
                      {msg.role === 'assistant' && msg.ai_model && (
                        <p className="mt-2 text-xs text-gray-400">
                          {msg.ai_provider} / {msg.ai_model}
                        </p>
                      )}
                    </div>
                  </div>
                ))
              )}
              {chatLoading && (
                <div className="flex justify-start">
                  <div className="bg-white px-4 py-3 rounded-2xl rounded-bl-md shadow-sm border border-gray-200">
                    <div className="flex items-center gap-2">
                      <Loader2 className="w-4 h-4 animate-spin text-gray-500" />
                      <span className="text-sm text-gray-500">考え中...</span>
                    </div>
                  </div>
                </div>
              )}
            </div>

            {/* 入力エリア */}
            <div className="border-t border-gray-200 p-4 bg-white">
              <div className="flex gap-3">
                <textarea
                  value={chatInput}
                  onChange={handleChatInputChange}
                  onKeyDown={(e) => {
                    if (e.nativeEvent.isComposing || e.keyCode === 229) return;
                    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
                      e.preventDefault();
                      sendChatMessage();
                    }
                  }}
                  placeholder="質問を入力... (⌘+Enterで送信)"
                  className="flex-1 px-4 py-3 text-sm border border-gray-300 rounded-xl resize-none focus:ring-2 focus:ring-gray-500 focus:border-gray-500"
                  rows={2}
                  disabled={chatLoading}
                  autoFocus
                />
                <button
                  onClick={sendChatMessage}
                  disabled={!chatInput.trim() || chatLoading}
                  className="px-4 py-3 bg-gray-600 text-white rounded-xl hover:bg-gray-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors self-end"
                >
                  {chatLoading ? (
                    <Loader2 className="w-5 h-5 animate-spin" />
                  ) : (
                    <Send className="w-5 h-5" />
                  )}
                </button>
              </div>
              <p className="mt-2 text-xs text-gray-400 text-center">
                ESCで閉じる
              </p>
            </div>
          </div>
        </div>
      )}

      {/* 本文表示モーダル */}
      {fullTextOpen && fullText && (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50" onClick={closeFullText}>
          <div
            className="w-full max-w-4xl h-[90vh] bg-white rounded-xl shadow-2xl flex flex-col overflow-hidden"
            onClick={(e) => e.stopPropagation()}
          >
            {/* モーダルヘッダー */}
            <div className="flex items-center justify-between px-4 py-3 bg-gradient-to-r from-emerald-500 to-teal-500 text-white">
              <div className="flex-1 min-w-0 mr-4">
                <h3 className="font-medium text-sm truncate">{paper.title}</h3>
                <div className="flex items-center gap-2 text-xs text-white/80">
                  <FileText className="w-3 h-3" />
                  <span>
                    論文本文
                    {fullTextSource && (
                      <span className="ml-1 px-1.5 py-0.5 bg-white/20 rounded text-[10px]">
                        {fullTextSource === 'unpaywall_pdf' ? 'PDF' : fullTextSource === 'html_scrape' ? 'HTML' : fullTextSource}
                      </span>
                    )}
                  </span>
                </div>
              </div>
              <button
                onClick={closeFullText}
                className="p-1.5 hover:bg-white/20 rounded transition-colors"
                title="閉じる (ESC)"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {/* 本文表示エリア */}
            <div className="flex-1 overflow-y-auto p-6 bg-gray-50">
              <div className="max-w-3xl mx-auto bg-white rounded-lg shadow-sm border border-gray-200 p-6 sm:p-8">
                <p className="text-sm sm:text-base text-gray-800 whitespace-pre-wrap leading-relaxed">
                  {fullText}
                </p>
              </div>
            </div>

            {/* フッター */}
            <div className="border-t border-gray-200 px-4 py-3 bg-white flex items-center justify-between">
              <p className="text-xs text-gray-400">
                ESCで閉じる
              </p>
              <a
                href={paper.url || '#'}
                target="_blank"
                rel="noopener noreferrer"
                className="flex items-center gap-1.5 text-xs text-gray-600 hover:text-gray-800"
              >
                <ExternalLink className="w-3.5 h-3.5" />
                元の論文を開く
              </a>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
