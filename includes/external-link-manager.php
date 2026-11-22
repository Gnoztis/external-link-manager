<?php
/**
 * Класс ELM_External_Link_Manager.
 *
 * @package ExternalLinkManager
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Управляет обработкой внешних ссылок в контенте.
 */
class ELM_External_Link_Manager {

    /**
     * Имя опции для списка исключённых доменов.
     *
     * @var string
     */
    private string $excluded_domains_option = 'elm_excluded_domains';

    /**
     * Имя опции для target="_blank".
     *
     * @var string
     */
    private string $target_blank_option = 'elm_target_blank';

    /**
     * Имя опции для rel="nofollow".
     *
     * @var string
     */
    private string $nofollow_option = 'elm_nofollow';

    /**
     * Запуск плагина.
     */
    public function run(): void {
        $this->add_content_filters();
    }

    /**
     * Добавляет фильтры для обработки контента.
     */
    private function add_content_filters(): void {
        $filters = [
            'the_content',
            'the_excerpt',
            'comment_text',
            'widget_text_content',
            'term_description',
        ];

        foreach ( $filters as $filter ) {
            add_filter( $filter, [ $this, 'process_links' ], 999 );
        }
    }

    /**
     * Обрабатывает ссылки в контенте.
     *
     * @param string $content Контент для обработки.
     * @return string Обработанный контент.
     */
    public function process_links( string $content ): string {
        if ( empty( $content ) || false === strpos( $content, '<a ' ) ) {
            return $content;
        }

        // Класс доступен с WordPress 6.4.
        if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
            return $content;
        }

        $processor = new WP_HTML_Tag_Processor( $content );
        $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
        $excluded  = $this->get_excluded_domains();

        if ( ! is_string( $home_host ) ) {
            return $content;
        }

        while ( $processor->next_tag( 'a' ) ) {
            $href = $processor->get_attribute( 'href' );
            if ( ! is_string( $href ) || '' === $href ) {
                continue;
            }

            // Пропускаем якоря.
            if ( '#' === $href[0] ) {
                continue;
            }

            $url_parts = wp_parse_url( $href );
            if ( ! is_array( $url_parts ) || ! isset( $url_parts['host'] ) ) {
                continue; // Относительная ссылка или ошибка парсинга.
            }

            $link_host = $url_parts['host'];

            // Внутренняя ссылка?
            if ( $link_host === $home_host ) {
                continue;
            }

            // Проверка исключений.
            if ( $this->is_excluded_domain( $link_host, $excluded ) ) {
                continue;
            }

            // target="_blank".
            if ( get_option( $this->target_blank_option, 1 ) ) {
                $processor->set_attribute( 'target', '_blank' );
            }

            // rel="nofollow" + noopener.
            if ( get_option( $this->nofollow_option, 1 ) ) {
                $current_rel = $processor->get_attribute( 'rel' );
                $rels        = ( is_string( $current_rel ) && '' !== $current_rel ) ? explode( ' ', $current_rel ) : [];

                if ( ! in_array( 'nofollow', $rels, true ) ) {
                    $rels[] = 'nofollow';
                }
                if ( get_option( $this->target_blank_option, 1 ) && ! in_array( 'noopener', $rels, true ) ) {
                    $rels[] = 'noopener';
                }

                $processor->set_attribute( 'rel', implode( ' ', $rels ) );
            }
        }

        return $processor->get_updated_html();
    }

    /**
     * Проверяет, исключён ли домен.
     *
     * @param string $domain   Домен для проверки.
     * @param array  $excluded Список исключённых доменов.
     * @return bool
     */
    private function is_excluded_domain( string $domain, array $excluded ): bool {
        return in_array( $domain, $excluded, true );
    }

    /**
     * Возвращает список исключённых доменов.
     *
     * @return array
     */
    private function get_excluded_domains(): array {
        $excluded = get_option( $this->excluded_domains_option, '' );
        if ( empty( $excluded ) || ! is_string( $excluded ) ) {
            return [];
        }

        // Нормализация: запятые и точки с запятой → переносы строк.
        $normalized = str_replace( [ ',', ';' ], "\n", $excluded );
        $lines      = explode( "\n", $normalized );
        $domains    = array_map( 'trim', $lines );
        $domains    = array_filter( $domains );

        // Извлекаем хост, если ввели полный URL.
        $domains = array_map(
            function ( string $item ): string {
                $parts = wp_parse_url( $item );
                return ( is_array( $parts ) && isset( $parts['host'] ) ) ? $parts['host'] : $item;
            },
            $domains
        );

        return array_unique( $domains );
    }
}